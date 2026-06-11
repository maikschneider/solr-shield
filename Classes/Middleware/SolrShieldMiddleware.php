<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Middleware;

use MaikSchneider\SolrShield\Service\BotDetectionService;
use MaikSchneider\SolrShield\Service\TokenService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * PSR-15 middleware providing two independent Solr security layers:
 * 1. URI obfuscation: every Solr request must carry a single signed `_ss` parameter
 *    containing the tx_solr payload (`p`) and a token signature (`s`).
 *    Direct tx_solr[…] requests are rejected.
 * 2. Bot detection: a JS-collected behavioural signal payload (_solr_bot) is
 *    validated; requests that fail the heuristics are rejected.
 * Settings are read from site settings (settings.definitions.yaml), making
 * them configurable per site without any TypoScript dependency.
 */
final class SolrShieldMiddleware implements MiddlewareInterface
{
    private const SUGGEST_PAGE_TYPE = 7384;

    public function __construct(
        private readonly TokenService $tokenService,
        private readonly BotDetectionService $botDetectionService,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $settings = $this->readSettings($request);

        if ($settings['uriObfuscation']['enabled']) {
            $request = $this->remapSParams($request);
        }

        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $isSuggestRequest = (int)($queryParams['type'] ?? 0) === self::SUGGEST_PAGE_TYPE;
        $hasTxSolr = $this->hasTxSolrParams($queryParams) || $this->hasTxSolrParams((array)($parsedBody ?? []));
        $hasSs = isset($queryParams['_ss']) || isset(((array)($parsedBody ?? []))['_ss']);

        // --- Protection 1: URI Obfuscation ---
        if ($settings['uriObfuscation']['enabled']) {
            if ($hasSs) {
                // Signed payload present — validate signature and decode
                $raw = $this->extractParam($queryParams, $parsedBody, '_ss');
                $decoded = $this->validateAndDecode($raw);
                if ($decoded === null) {
                    return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
                }
                // For suggest: signature is all we need; no tx_solr params to inject.
                // Always call injectDecodedParams for non-suggest so _ss is stripped
                // even when decoded is empty (no-JS form path via _s[…] remapping).
                if (!$isSuggestRequest) {
                    $request = $this->injectDecodedParams($request, $decoded);
                }
            } elseif ($hasTxSolr || $isSuggestRequest) {
                // Direct tx_solr request or suggest without signed payload → reject
                return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
            }
        }

        // --- Protection 2: Bot Detection ---
        if ($settings['botDetection']['enabled'] && !$isSuggestRequest && $hasSs) {
            $botPayload = $this->extractParam($queryParams, $parsedBody, '_solr_bot');
            if ($botPayload === '') {
                return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
            }
            if (!$this->botDetectionService->isHuman($botPayload, $settings['botDetection'])) {
                return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
            }
            $request = $this->stripParams($request, ['_solr_bot']);
        }

        return $handler->handle($request);
    }

    /**
     * Read settings from the site settings (set via settings.definitions.yaml).
     * Falls back to the defaults defined in settings.definitions.yaml.
     */
    private function readSettings(ServerRequestInterface $request): array
    {
        $site = $request->getAttribute('site');
        $siteSettings = $site instanceof Site ? $site->getSettings() : null;

        return [
            'uriObfuscation' => [
                'enabled' => (bool)($siteSettings?->get('solrShield.uriObfuscation.enabled', true) ?? true),
                'rejectAction' => (string)($siteSettings?->get('solrShield.uriObfuscation.rejectAction', 'redirect') ?? 'redirect'),
            ],
            'botDetection' => [
                'enabled' => (bool)($siteSettings?->get('solrShield.botDetection.enabled', true) ?? true),
                'minFormTimeout' => (int)($siteSettings?->get('solrShield.botDetection.minFormTimeout', 3) ?? 3),
                'securityLevel' => (int)($siteSettings?->get('solrShield.botDetection.securityLevel', 7) ?? 7),
                'requireInteraction' => (bool)($siteSettings?->get('solrShield.botDetection.requireInteraction', true) ?? true),
            ],
        ];
    }

    /**
     * Map `_s[…]` parameters back to `tx_solr[…]` for the no-JS form path.
     * HtmlOutputObfuscationMiddleware replaces `name="tx_solr[` with `name="_s[`
     * in the rendered HTML; this reverses that mapping on inbound requests.
     */
    private function remapSParams(ServerRequestInterface $request): ServerRequestInterface
    {
        $queryParams = $this->remapSArray($request->getQueryParams());
        $parsedBody = $this->remapSArray((array)($request->getParsedBody() ?? []));

        return $request
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody ?: null);
    }

    private function remapSArray(array $params): array
    {
        if (!isset($params['_s']) || !is_array($params['_s'])) {
            return $params;
        }
        $params['tx_solr'] = $params['_s'];
        unset($params['_s']);
        return $params;
    }

    private function hasTxSolrParams(array $params): bool
    {
        foreach (array_keys($params) as $key) {
            if (str_starts_with((string)$key, 'tx_solr')) {
                return true;
            }
        }
        return false;
    }

    private function extractParam(array $queryParams, mixed $parsedBody, string $name): string
    {
        return (string)($queryParams[$name]
            ?? ((array)($parsedBody ?? []))[$name]
            ?? '');
    }

    /**
     * Validate the token signature embedded in `_ss` and decode the payload.
     * Format: base64(JSON({"p": "<querystring>", "s": "<token>"}))
     * Returns the decoded tx_solr params on success (may be empty array for suggest),
     * or null if the signature is invalid or the payload is malformed.
     */
    private function validateAndDecode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $json = base64_decode($raw, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['s'], $data['p']) || !is_string($data['s']) || !is_string($data['p'])) {
            return null;
        }
        if (!$this->tokenService->isValidToken($data['s'])) {
            return null;
        }
        if ($data['p'] === '') {
            return [];
        }
        parse_str($data['p'], $decoded);
        return $decoded !== [] ? $decoded : [];
    }

    private function reject(ServerRequestInterface $request, string $action): ResponseInterface
    {
        if ($action === '403') {
            return $this->responseFactory->createResponse(403);
        }

        $referer = $request->getHeaderLine('Referer');
        return new RedirectResponse($referer !== '' ? $referer : '/', 302);
    }

    private function injectDecodedParams(ServerRequestInterface $request, array $decodedParams): ServerRequestInterface
    {
        $queryParams = $request->getQueryParams();
        unset($queryParams['_ss']);
        $queryParams = array_merge($queryParams, $decodedParams);

        $parsedBody = (array)($request->getParsedBody() ?? []);
        unset($parsedBody['_ss']);

        return $request
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody ?: null);
    }

    private function stripParams(ServerRequestInterface $request, array $paramNames): ServerRequestInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = (array)($request->getParsedBody() ?? []);

        foreach ($paramNames as $name) {
            unset($queryParams[$name], $parsedBody[$name]);
        }

        return $request
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody ?: null);
    }
}
