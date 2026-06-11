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
 * field carrying a server-signed token so that SolrShieldMiddleware can
 * validate and remap the submitted `_s[…]` parameters back to `tx_solr[…]`
 * without requiring JavaScript.
 */
final class HtmlOutputObfuscationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly StreamFactoryInterface $streamFactory,
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
        $modified = $this->rewriteFieldNames($body);

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
     * Inject a hidden `_ss` token field into every form that carries `_s[`
     * inputs so that SolrShieldMiddleware can validate the submission without JS.
     */
    private function injectSsTokenIntoForms(string $html): string
    {
        $payload = $this->buildEmptySsPayload();
        $hiddenField = '<input type="hidden" name="_ss" value="' . htmlspecialchars($payload, ENT_QUOTES) . '">';

        return (string)preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/si',
            static function (array $matches) use ($hiddenField): string {
                $form = $matches[0];
                if (!str_contains($form, 'name="_s[') && !str_contains($form, "name='_s[")) {
                    return $form;
                }
                return str_replace('</form>', $hiddenField . '</form>', $form);
            },
            $html
        ) ?? $html;
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
