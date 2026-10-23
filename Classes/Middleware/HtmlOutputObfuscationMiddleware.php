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
 * field carrying a server-signed token so that SolrShieldMiddleware can
 * validate and remap the submitted `_s[…]` parameters back to `tx_solr[…]`
 * without requiring JavaScript.
 *
 * EXT:solr's range facet scripts are swapped for patched copies that can
 * fill a range into the signed `_ss` payload.
 */
final readonly class HtmlOutputObfuscationMiddleware implements MiddlewareInterface
{
    private const RANGE_FACET_SCRIPTS = ['facet_daterange_controller', 'facet_numericrange_controller'];

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
        $payload = $this->buildEmptySsPayload();

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
     * Inject a hidden `_ss` token field into every form that carries `_s[`
     * inputs so that SolrShieldMiddleware can validate the submission without JS.
     */
    private function injectSsTokenIntoForms(string $html): string
    {
        $payload = $this->buildEmptySsPayload();
        $hiddenField = '<input type="hidden" name="_ss" value="' . htmlspecialchars($payload, ENT_QUOTES) . '">';

        $result = preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/si',
            static function (array $matches) use ($hiddenField): string {
                $form = $matches[0];
                if (!str_contains($form, 'name="_s[') && !str_contains($form, "name='_s[")) {
                    return $form;
                }
                return str_replace('</form>', $hiddenField . '</form>', $form);
            },
            $html
        );

        return $result ?? $html;
    }

    /**
     * Build a signed empty-payload `_ss` value (token-only, no tx_solr params).
     * SolrShieldMiddleware treats this as a valid proof-of-origin that decodes to [].
     */
    private function buildEmptySsPayload(): string
    {
        return base64_encode((string)json_encode([
            'p' => '',
            's' => $this->tokenService->generateToken(),
        ]));
    }
}
