<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Middleware;

use MaikSchneider\SolrShield\Service\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Rewrites tx_solr form field names in the final HTML response so that
 * `tx_solr[…]` never appears in the browser source or submitted URLs.
 *
 * Every `name="tx_solr[` attribute is replaced with `name="_s[`.
 * Forms that contain such renamed inputs receive an injected hidden `_ss`
 * field signed for exactly that form, so that SolrShieldMiddleware can
 * validate and remap the submitted `_s[…]` parameters back to `tx_solr[…]`
 * without requiring JavaScript.
 */
final readonly class HtmlOutputObfuscationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenService $tokenService,
        private StreamFactoryInterface $streamFactory,
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
        $modified = $this->signSuggestUrls($this->rewriteFieldNames($body));

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
     * Inject a hidden `_ss` field into every form that carries `_s[` fields.
     * Its payload signs the values of the form's hidden `_s[…]` fields and the
     * names of the fields the visitor fills in, so a submission can neither
     * change a hidden value nor add a field the form does not have.
     */
    private function injectSsTokenIntoForms(string $html): string
    {
        $result = preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/si',
            function (array $matches): string {
                $form = $matches[0];
                if (!str_contains($form, 'name="_s[') && !str_contains($form, "name='_s[")) {
                    return $form;
                }
                $hiddenField = '<input type="hidden" name="_ss" value="' . htmlspecialchars($this->signForm($form), ENT_QUOTES) . '">';
                return substr_replace($form, $hiddenField, (int)strripos($form, '</form>'), 0);
            },
            $html
        );

        return $result ?? $html;
    }

    private function signForm(string $form): string
    {
        $fixed = [];
        $free = [];
        preg_match_all('/<(?:input|select|textarea|button)\b[^>]*>/si', $form, $tags);
        foreach ($tags[0] as $tag) {
            $name = $this->attribute($tag, 'name');
            if ($name === null || !str_starts_with($name, '_s[')) {
                continue;
            }
            $path = substr($name, 2);
            if (str_starts_with($tag, '<input') && strtolower((string)$this->attribute($tag, 'type')) === 'hidden') {
                $fixed[] = rawurlencode('tx_solr' . $path) . '=' . rawurlencode((string)$this->attribute($tag, 'value'));
            } else {
                $free[$path] = $path;
            }
        }

        return $this->tokenService->encode(implode('&', $fixed), array_values($free));
    }

    private function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . $name . '\s*=\s*(["\'])(.*?)\1/si', $tag, $matches) !== 1) {
            return null;
        }
        return html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
    }
}
