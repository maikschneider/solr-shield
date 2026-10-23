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
 * 1. URI obfuscation: every Solr request must carry a signed `_ss` parameter
 *    whose signature covers the tx_solr payload (see TokenService).
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

        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $isSuggestRequest = (int)($queryParams['type'] ?? 0) === self::SUGGEST_PAGE_TYPE;
        $hasSs = isset($queryParams['_ss']) || isset(((array)($parsedBody ?? []))['_ss']);

        // --- Protection 1: URI Obfuscation ---
        if ($settings['uriObfuscation']['enabled']) {
            $decodedRequest = $this->decodeSolrParameters($request, $isSuggestRequest);
            if ($decodedRequest === null) {
                return $this->reject($request, $settings['uriObfuscation']['rejectAction']);
            }
            $request = $decodedRequest;
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
                // Defaults off on main: the client-side JS that produces the
                // _solr_bot payload lives on the feature/js-bot-detection branch.
                'enabled' => (bool)($siteSettings?->get('solrShield.botDetection.enabled', false) ?? false),
                'minFormTimeout' => (int)($siteSettings?->get('solrShield.botDetection.minFormTimeout', 3) ?? 3),
                'securityLevel' => (int)($siteSettings?->get('solrShield.botDetection.securityLevel', 7) ?? 7),
                'requireInteraction' => (bool)($siteSettings?->get('solrShield.botDetection.requireInteraction', true) ?? true),
            ],
        ];
    }

    /**
     * Turn the signed `_ss` payload and, for forms, the `_s[…]` fields back
     * into `tx_solr` parameters. Returns null when the request must be rejected.
     *
     * - Raw `tx_solr` parameters are never accepted, except the typed
     *   `tx_solr[queryString]` of an autocomplete request.
     * - `_s[…]` fields must be listed as free fields in the signed payload of
     *   their form, or match one of its signed hidden values.
     */
    private function decodeSolrParameters(ServerRequestInterface $request, bool $isSuggestRequest): ?ServerRequestInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = (array)($request->getParsedBody() ?? []);
        $rawSs = $this->extractParam($queryParams, $parsedBody, '_ss');
        $hasFormFields = isset($queryParams['_s']) || isset($parsedBody['_s']);

        if ($isSuggestRequest) {
            if ($this->hasTxSolrParams($parsedBody) || $hasFormFields || !$this->isTypedSuggestQuery($queryParams)) {
                return null;
            }
        } elseif ($this->hasTxSolrParams($queryParams) || $this->hasTxSolrParams($parsedBody)) {
            return null;
        }

        if ($rawSs === '') {
            return ($isSuggestRequest || $hasFormFields || isset($queryParams['_ss']) || isset($parsedBody['_ss'])) ? null : $request;
        }
        $payload = $this->tokenService->decode($rawSs);
        if ($payload === null) {
            return null;
        }
        unset($queryParams['_ss'], $parsedBody['_ss']);

        if ($isSuggestRequest) {
            $queryParams = array_replace_recursive($queryParams, $payload['params']);
        } elseif ($hasFormFields) {
            if (isset($queryParams['_s'], $parsedBody['_s'])) {
                return null;
            }
            $inBody = isset($parsedBody['_s']);
            $submitted = $inBody ? $parsedBody['_s'] : $queryParams['_s'];
            $signed = is_array($payload['params']['tx_solr'] ?? null) ? $payload['params']['tx_solr'] : [];
            if (!is_array($submitted) || !$this->isAllowedFormSubmission($submitted, $signed, $payload['freeFields'])) {
                return null;
            }
            $txSolr = array_replace_recursive($submitted, $signed);
            if ($inBody) {
                unset($parsedBody['_s']);
                $parsedBody['tx_solr'] = $txSolr;
            } else {
                unset($queryParams['_s']);
                $queryParams['tx_solr'] = $txSolr;
            }
        } else {
            $queryParams = array_merge($queryParams, $payload['params']);
        }

        return $request
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody ?: null);
    }

    /**
     * @param array<array-key, mixed> $queryParams
     */
    private function isTypedSuggestQuery(array $queryParams): bool
    {
        foreach ($queryParams as $key => $value) {
            if (!str_starts_with((string)$key, 'tx_solr')) {
                continue;
            }
            if ($key !== 'tx_solr' || !is_array($value) || array_keys($value) !== ['queryString'] || !is_string($value['queryString'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<array-key, mixed> $submitted
     * @param array<array-key, mixed> $signed
     * @param list<string> $freeFields
     */
    private function isAllowedFormSubmission(array $submitted, array $signed, array $freeFields): bool
    {
        $signedValues = $this->flatten($signed);
        foreach ($this->flatten($submitted) as $path => $value) {
            if (array_key_exists($path, $signedValues)) {
                if ($signedValues[$path] !== $value) {
                    return false;
                }
                continue;
            }
            if (!$this->isFreeField($path, $freeFields)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<string> $freeFields
     */
    private function isFreeField(string $path, array $freeFields): bool
    {
        foreach ($freeFields as $freeField) {
            if ($path === $freeField) {
                return true;
            }
            if (str_ends_with($freeField, '[]')
                && preg_match('/^' . preg_quote(substr($freeField, 0, -2), '/') . '\[\d+\]$/', $path) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flatten nested parameters into `[a][b]` paths, the notation of the field
     * names in the form.
     *
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function flatten(array $params, string $prefix = ''): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            $path = $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }
        return $flat;
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

    private function reject(ServerRequestInterface $request, string $action): ResponseInterface
    {
        if ($action === '403') {
            return $this->responseFactory->createResponse(403);
        }

        $referer = $request->getHeaderLine('Referer');
        return new RedirectResponse($referer !== '' ? $referer : '/', 302);
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
