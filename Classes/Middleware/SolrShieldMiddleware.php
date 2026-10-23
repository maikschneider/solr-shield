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
final readonly class SolrShieldMiddleware implements MiddlewareInterface
{
    private const SUGGEST_PAGE_TYPE = 7384;

    public function __construct(
        private TokenService $tokenService,
        private BotDetectionService $botDetectionService,
        private ResponseFactoryInterface $responseFactory,
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
                // Suggest requests carry the typed tx_solr[queryString] next to
                // the signed additionalFilters, so the payload is merged into
                // the request instead of replacing its tx_solr parameters.
                $request = $this->injectDecodedParams($request, $decoded, $isSuggestRequest);
            } elseif ($hasTxSolr || $isSuggestRequest) {
                // Direct tx_solr request or suggest without signed payload → reject
                return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
            }
        }

        // --- Protection 2: Bot Detection ---
        if ($settings['botDetection']['enabled'] && !$isSuggestRequest && $hasSs) {
            // A form posted to a URL that came from a signed link carries that
            // link's older payload in the query; the one in the body is fresh.
            $botPayload = $this->extractParam([], $parsedBody, '_solr_bot');
            if ($botPayload === '') {
                $botPayload = $this->extractParam($queryParams, null, '_solr_bot');
            }
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
     *
     * @return array{
     *     uriObfuscation: array{enabled: bool, rejectAction: string},
     *     botDetection: array{enabled: bool, minFormTimeout: int, securityLevel: int, requireInteraction: bool}
     * }
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
                // Off by default: visitors without JavaScript cannot send a payload.
                'enabled' => (bool)($siteSettings?->get('solrShield.botDetection.enabled', false) ?? false),
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

    /**
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    private function remapSArray(array $params): array
    {
        if (!isset($params['_s']) || !is_array($params['_s'])) {
            return $params;
        }
        $params['tx_solr'] = $params['_s'];
        unset($params['_s']);
        return $params;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function hasTxSolrParams(array $params): bool
    {
        foreach (array_keys($params) as $key) {
            if (str_starts_with((string)$key, 'tx_solr')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<array-key, mixed> $queryParams
     */
    private function extractParam(array $queryParams, mixed $parsedBody, string $name): string
    {
        $value = $queryParams[$name] ?? ((array)($parsedBody ?? []))[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Validate the token signature embedded in `_ss` and decode the payload.
     * Format: base64(JSON({"p": "<querystring>", "s": "<token>"}))
     * Returns the decoded tx_solr params on success (may be empty array for suggest),
     * or null if the signature is invalid or the payload is malformed.
     *
     * @return array<array-key, mixed>|null
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

    /**
     * @param array<array-key, mixed> $decodedParams
     */
    private function injectDecodedParams(ServerRequestInterface $request, array $decodedParams, bool $merge): ServerRequestInterface
    {
        $queryParams = $request->getQueryParams();
        unset($queryParams['_ss']);
        $queryParams = $merge
            ? array_replace_recursive($queryParams, $decodedParams)
            : array_merge($queryParams, $decodedParams);

        $parsedBody = (array)($request->getParsedBody() ?? []);
        unset($parsedBody['_ss']);

        return $request
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody ?: null);
    }

    /**
     * @param list<string> $paramNames
     */
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
