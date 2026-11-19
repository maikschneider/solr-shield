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
use TYPO3\CMS\Core\Http\Uri;
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
                return $this->reject($request, $settings['uriObfuscation']['rejectAction'], $isSuggestRequest);
            }
            $request = $decodedRequest;
        }

        // --- Protection 2: Bot Detection ---
        if ($settings['botDetection']['enabled'] && $hasSs) {
            // A form posted to a URL that came from a signed link carries that
            // link's older payload in the query; the one in the body is fresh.
            $botPayload = $this->extractParam([], $parsedBody, '_solr_bot');
            if ($botPayload === '') {
                $botPayload = $this->extractParam($queryParams, null, '_solr_bot');
            }
            $botSettings = $settings['botDetection'];
            if ($isSuggestRequest) {
                // Visitors often start typing right after the page has loaded.
                $botSettings['minFormTimeout'] = 0;
            }
            if ($botPayload === '' || !$this->botDetectionService->isHuman($botPayload, $botSettings)) {
                return $this->reject($request, $settings['uriObfuscation']['rejectAction'], $isSuggestRequest);
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
                'rejectAction' => (string)($siteSettings?->get('solrShield.uriObfuscation.rejectAction', '403') ?? '403'),
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
        // A form posted to a URL that came from a signed link carries the link's
        // `_ss` in the query; the form's own `_ss`, which signs its fields, is in the body.
        $rawSs = $this->extractParam([], $parsedBody, '_ss');
        if ($rawSs === '') {
            $rawSs = $this->extractParam($queryParams, null, '_ss');
        }
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
        $payload['params'] = $this->fillRangePlaceholders($payload['params'], $queryParams['_sr'] ?? null);
        if ($payload['params'] === null) {
            return null;
        }
        unset($queryParams['_ss'], $queryParams['_sr'], $parsedBody['_ss']);

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
     * Range facets sign a URL template, so the browser cannot fill in the
     * range without breaking the signature. It sends the range as
     * `_sr[from]` and `_sr[to]` instead, which replace the `___FROM___` and
     * `___TO___` placeholders of the signed parameters. Only numbers are
     * accepted, so nothing but the range bounds can be changed.
     *
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>|null
     */
    private function fillRangePlaceholders(array $params, mixed $range): ?array
    {
        if ($range === null) {
            return $params;
        }
        if (!is_array($range) || $range === [] || array_diff_key($range, ['from' => true, 'to' => true]) !== []) {
            return null;
        }
        $replacements = [];
        foreach ($range as $bound => $value) {
            if (!is_string($value) || preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                return null;
            }
            $replacements['___' . strtoupper((string)$bound) . '___'] = $value;
        }
        array_walk_recursive($params, static function (mixed &$value) use ($replacements): void {
            if (is_string($value)) {
                $value = strtr($value, $replacements);
            }
        });

        return $params;
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

    /**
     * Suggest requests come from a script that expects JSON, so they are never redirected.
     */
    private function reject(ServerRequestInterface $request, string $action, bool $isSuggestRequest): ResponseInterface
    {
        if ($action === '403' || $isSuggestRequest) {
            return $this->responseFactory->createResponse(403);
        }

        return new RedirectResponse($this->redirectTarget($request), 302);
    }

    /**
     * The referer, if it is on the same scheme, host and port as the request,
     * otherwise the site root. The target is rebuilt from the request URI so
     * that only the referer's path and query are taken over.
     */
    private function redirectTarget(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        $fallback = $site instanceof Site ? (string)$site->getBase() : '/';

        try {
            $referer = new Uri($request->getHeaderLine('Referer'));
        } catch (\InvalidArgumentException) {
            return $fallback;
        }
        $uri = $request->getUri();
        if ($referer->getScheme() !== $uri->getScheme()
            || $referer->getHost() !== $uri->getHost()
            || $referer->getPort() !== $uri->getPort()
        ) {
            return $fallback;
        }

        return (string)$uri
            ->withUserInfo('')
            ->withPath($referer->getPath() !== '' ? $referer->getPath() : '/')
            ->withQuery($referer->getQuery())
            ->withFragment('');
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
