<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Middleware;

use MaikSchneider\SolrShield\Service\PublicResourceResolver;
use MaikSchneider\SolrShield\Service\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Rewrites tx_solr form field names in the final HTML response so that
 * `tx_solr[…]` never appears in the browser source or submitted URLs.
 *
 * Every `name="tx_solr[` attribute is replaced with `name="_s[`.
 * Forms that contain such renamed inputs receive an injected hidden `_ss`
 * field signed for exactly that form, so that SolrShieldMiddleware can
 * validate and remap the submitted `_s[…]` parameters back to `tx_solr[…]`
 * without requiring JavaScript.
 *
 * EXT:solr's range facet scripts are swapped for patched copies that can
 * fill a range into the signed `_ss` payload.
 */
final readonly class HtmlOutputObfuscationMiddleware implements MiddlewareInterface
{
    private const RANGE_FACET_SCRIPTS = ['facet_daterange_controller', 'facet_numericrange_controller'];

    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    public function __construct(
        private TokenService $tokenService,
        private StreamFactoryInterface $streamFactory,
        private PublicResourceResolver $resourceResolver,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->isObfuscationEnabled($request)) {
            return $response;
        }

        if (!str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            return $response;
        }

        $body = (string)$response->getBody();
        $modified = $this->replaceRangeFacetScripts($this->signSuggestUrls($this->rewriteFieldNames($body)));

        if ($modified === $body) {
            return $response;
        }

        $modified = $this->injectSsTokenIntoForms($modified);

        $stream = $this->streamFactory->createStream($modified);

        return $response
            ->withBody($stream)
            ->withHeader('Content-Length', (string)strlen($modified));
    }

    private function isObfuscationEnabled(ServerRequestInterface $request): bool
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return true;
        }
        return (bool)$site->getSettings()->get('solrShield.uriObfuscation.enabled', true);
    }

    /**
     * Replace `name="tx_solr[` and `name='tx_solr['` with the obfuscated prefix.
     */
    private function rewriteFieldNames(string $html): string
    {
        return str_replace(
            ['name="tx_solr[', "name='tx_solr["],
            ['name="_s[', "name='_s["],
            $html
        );
    }

    /**
     * Append a signed empty payload to EXT:solr's `data-suggest` URLs that
     * carry none, so autocomplete requests pass SolrShieldMiddleware. URLs
     * with `tx_solr[additionalFilters]` already got `_ss` from the link listener.
     */
    private function signSuggestUrls(string $html): string
    {
        $payload = $this->tokenService->encode('');

        $result = preg_replace_callback(
            '/(\sdata-suggest=)(["\'])(.*?)\2/si',
            static function (array $matches) use ($payload): string {
                $url = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5);
                parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
                if (isset($query['_ss'])) {
                    return $matches[0];
                }
                $url .= (str_contains($url, '?') ? '&' : '?') . '_ss=' . rawurlencode($payload);
                return $matches[1] . $matches[2] . htmlspecialchars($url, ENT_QUOTES) . $matches[2];
            },
            $html
        );

        return $result ?? $html;
    }

    /**
     * Point script tags that load EXT:solr's range facet controllers to the
     * patched copies. Their URL templates sit inside the signed `_ss` payload,
     * and they navigate through a link click so solr-shield.js can sign it.
     */
    private function replaceRangeFacetScripts(string $html): string
    {
        $version = (new Typo3Version())->getMajorVersion() >= 14 ? 'v14' : 'v13';

        foreach (self::RANGE_FACET_SCRIPTS as $script) {
            if (!str_contains($html, $script)) {
                continue;
            }
            $stock = $this->resourceResolver->resolve('EXT:solr/Resources/Public/JavaScript/' . $script . '.js');
            $patchedResource = 'EXT:solr_shield/Resources/Public/JavaScript/Solr/' . $version . '/' . $script . '.js';
            $patched = htmlspecialchars($this->resourceResolver->resolve($patchedResource, true), ENT_QUOTES);
            // FE.versionNumberInFilename turns `x.js` into `x.1761211273.js`.
            $stockPattern = preg_quote(substr($stock, 0, -3), '/') . '(?:\.\d+)?\.js(?:\?[^"\']*)?';

            $html = preg_replace_callback(
                '/<script\b[^>]*\ssrc=(["\'])[^"\']*' . $stockPattern . '\1[^>]*>/i',
                fn (array $matches): string => $this->pointScriptTagTo($matches[0], $patched, $patchedResource),
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Replace the `src` of a script tag and, if it has one, its `integrity`
     * hash, which would otherwise make the browser refuse the patched file.
     */
    private function pointScriptTagTo(string $tag, string $src, string $resource): string
    {
        $tag = (string)preg_replace('/(\ssrc=)(["\'])[^"\']*\2/i', '$1$2' . $src . '$2', $tag);
        if (!preg_match('/\sintegrity=(["\'])[^"\']*\1/i', $tag)) {
            return $tag;
        }
        $contents = (string)file_get_contents(GeneralUtility::getFileAbsFileName($resource));
        $integrity = 'sha384-' . base64_encode(hash('sha384', $contents, true));

        return (string)preg_replace('/(\sintegrity=)(["\'])[^"\']*\2/i', '$1$2' . $integrity . '$2', $tag);
    }

    /**
     * Inject a hidden `_ss` field into every form that owns `_s[` fields,
     * including fields linked to it through the `form` attribute.
     * Its payload signs the values of the form's hidden `_s[…]` fields and the
     * names of the fields the visitor fills in, so a submission can neither
     * change a hidden value nor add a field the form does not have.
     */
    private function injectSsTokenIntoForms(string $html): string
    {
        [$forms, $controls] = $this->findFormsAndControls($html);

        $formIndexById = [];
        foreach ($forms as $index => [$id]) {
            if ($id !== '') {
                $formIndexById[$id] ??= $index;
            }
        }

        // Like the browser, a control with a `form` attribute belongs to the
        // form with that id, or to no form if there is none, wherever it sits.
        $controlsByForm = [];
        foreach ($controls as [$parentForm, $tagName, $attributes]) {
            $owner = isset($attributes['form']) ? $formIndexById[$attributes['form']] ?? null : $parentForm;
            if ($owner !== null && $forms[$owner][1] !== null) {
                $controlsByForm[$owner][] = [$tagName, $attributes];
            }
        }

        // Insert from the last form backwards, so the offsets of earlier forms stay valid.
        krsort($controlsByForm);
        foreach ($controlsByForm as $index => $formControls) {
            $hiddenField = '<input type="hidden" name="_ss" value="' . htmlspecialchars($this->signForm($formControls), ENT_QUOTES) . '">';
            $html = substr_replace($html, $hiddenField, (int)$forms[$index][1], 0);
        }

        return $html;
    }

    /**
     * All forms and enabled `_s[…]` controls of the document, in document order.
     *
     * @return array{list<array{string, ?int}>, list<array{?int, string, array<string, string>}>}
     *     forms with id and offset of their end tag (null if unclosed), and
     *     controls with their enclosing form, lowercase tag name and attributes
     */
    private function findFormsAndControls(string $html): array
    {
        // Every tag is consumed whole, so tag-like text inside an attribute
        // value, a comment, a script, a style or a textarea never counts as a tag.
        // A tag name ends at whitespace, `/` or `>`, so `<textarea-wrapper>` is no textarea.
        // Possessive loops keep large inline content within PCRE's backtrack limit.
        $attributePattern = '((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)';
        $found = preg_match_all(
            '/<!--[^-]*+(?:-(?!->)[^-]*+)*+-->'
            . '|<(script|style)(?=[\t\n\f\r \/>])[^<]*+(?:<(?!\/\1\s*>)[^<]*+)*+<\/\1\s*>'
            . '|(?|<(textarea)(?=[\t\n\f\r \/>])' . $attributePattern . '>[^<]*+(?:<(?!\/textarea\s*>)[^<]*+)*+<\/textarea\s*>'
            . '|<([a-z][^\t\n\f\r \/>]*+)' . $attributePattern . '>)'
            . '|<\/([a-z][^\t\n\f\r \/>]*+)[^>]*+>/is',
            $html,
            $tags,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        );
        if ($found === false) {
            throw new \RuntimeException('Could not find the forms to sign: ' . preg_last_error_msg(), 1795110108);
        }
        $forms = [];
        $controls = [];
        $openForm = null;
        // The content of a template is no part of the document.
        $templateDepth = 0;
        // Per open fieldset: whether it is disabled, whether its first legend
        // child, which it does not disable, is not yet seen (0), open (1) or
        // closed (2), and the elements open inside it until that legend is seen.
        $fieldsets = [];
        foreach ($tags as $tag) {
            // Start tags by name, end tags as `/name`.
            $tagName = strtolower($tag[2][0] ?? (isset($tag[4][0]) ? '/' . $tag[4][0] : ''));
            if ($tagName === 'template' || $tagName === '/template') {
                $templateDepth = max(0, $templateDepth + ($tagName === 'template' ? 1 : -1));
                continue;
            }
            if ($templateDepth > 0) {
                continue;
            }
            if ($tagName === '/fieldset') {
                array_pop($fieldsets);
            }
            $innermostFieldset = array_key_last($fieldsets);
            if ($innermostFieldset !== null && $fieldsets[$innermostFieldset][1] === 0) {
                $fieldsets[$innermostFieldset] = $this->trackFirstLegendChild($fieldsets[$innermostFieldset], $tagName);
            }
            switch ($tagName) {
                case 'form':
                    // Like the browser, ignore a form inside a form.
                    if ($openForm === null) {
                        $forms[] = [$this->parseAttributes((string)$tag[3][0])['id'] ?? '', null];
                        $openForm = array_key_last($forms);
                    }
                    break;
                case '/form':
                    if ($openForm !== null) {
                        $forms[$openForm] = [$forms[$openForm][0], $tag[0][1]];
                        $openForm = null;
                    }
                    break;
                case 'fieldset':
                    $fieldsets[] = [isset($this->parseAttributes((string)$tag[3][0])['disabled']), 0, []];
                    break;
                case '/legend':
                    if ($innermostFieldset !== null && $fieldsets[$innermostFieldset][1] === 1) {
                        $fieldsets[$innermostFieldset][1] = 2;
                    }
                    break;
                case 'input':
                case 'select':
                case 'button':
                case 'textarea':
                    // Unparsable controls are neither signed nor free, so their submission is rejected.
                    $attributes = $this->parseAttributes((string)$tag[3][0]);
                    // The browser does not submit a disabled field.
                    if (str_starts_with($attributes['name'] ?? '', '_s[') && !isset($attributes['disabled']) && !$this->isDisabledByFieldset($fieldsets)) {
                        $controls[] = [$openForm, $tagName, $attributes];
                    }
            }
        }

        return [$forms, $controls];
    }

    /**
     * Only a legend that is a direct child of the fieldset is its first legend.
     * Markup that does not nest strictly gives up on the legend, so its
     * controls stay disabled rather than being signed although the browser
     * omits them.
     *
     * @param array{bool, int, list<string>} $fieldset
     * @return array{bool, int, list<string>}
     */
    private function trackFirstLegendChild(array $fieldset, string $tagName): array
    {
        [$disabled, , $openElements] = $fieldset;
        if ($tagName === 'legend' && $openElements === []) {
            return [$disabled, 1, []];
        }
        if (str_starts_with($tagName, '/')) {
            $name = substr($tagName, 1);
            if (end($openElements) === $name) {
                array_pop($openElements);
            } elseif (in_array($name, $openElements, true)) {
                return [$disabled, 2, []];
            }
        } elseif ($tagName !== '' && $tagName !== 'textarea' && !in_array($tagName, self::VOID_ELEMENTS, true)) {
            $openElements[] = $tagName;
        }

        return [$disabled, 0, $openElements];
    }

    /**
     * @param list<array{bool, int, list<string>}> $fieldsets
     */
    private function isDisabledByFieldset(array $fieldsets): bool
    {
        foreach ($fieldsets as [$disabled, $legend]) {
            if ($disabled && $legend !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{string, array<string, string>}> $controls lowercase tag name and attributes
     */
    private function signForm(array $controls): string
    {
        $fixed = [];
        $free = [];
        foreach ($controls as [$tagName, $attributes]) {
            $path = substr($attributes['name'], 2);
            if ($tagName === 'input' && strtolower($attributes['type'] ?? '') === 'hidden') {
                $fixed[] = [$path, $attributes['value'] ?? ''];
            } else {
                $free[$path] = $path;
            }
        }

        $query = [];
        foreach ($fixed as [$path, $value]) {
            // A name without `[]` carries one value, so an editable control with
            // the same name (e.g. a checkbox after its hidden fallback) replaces it.
            if (isset($free[$path]) && !str_ends_with($path, '[]')) {
                continue;
            }
            $query[] = rawurlencode('tx_solr' . $path) . '=' . rawurlencode($value);
        }

        return $this->tokenService->encode(implode('&', $query), array_values($free));
    }

    /**
     * Tokenize attributes left to right like the browser does, so text inside a
     * quoted value never counts as another attribute.
     *
     * @return array<string, string> lowercase names, the first occurrence wins; empty if unparsable
     */
    private function parseAttributes(string $attributeString): array
    {
        $attributes = [];
        $offset = 0;
        $pattern = '/\G[\s\/]*([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';
        while (preg_match($pattern, $attributeString, $matches, PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $offset += strlen((string)$matches[0]);
            $attributes[strtolower((string)$matches[1])] ??= html_entity_decode(
                $matches[2] ?? $matches[3] ?? $matches[4] ?? '',
                ENT_QUOTES | ENT_HTML5
            );
        }

        return preg_match('/^[\s\/]*$/', substr($attributeString, $offset)) === 1 ? $attributes : [];
    }
}
