<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use MaikSchneider\SolrShield\Service\TokenService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractMiddlewareTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'reports',
        'scheduler',
        'tstemplate',
    ];

    protected array $testExtensionsToLoad = [
        'apache-solr-for-typo3/solr',
        'maikschneider/solr-shield',
        __DIR__ . '/Fixtures/Extensions/solr_shield_test',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/default' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $headers
     */
    protected function request(string $uri, ?array $parsedBody = null, array $headers = []): ResponseInterface
    {
        $request = new InternalRequest('http://localhost' . $uri);
        if ($parsedBody !== null) {
            $request = $request
                ->withMethod('POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($parsedBody);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @return array{query: array<array-key, mixed>, body: array<array-key, mixed>|null}
     */
    protected function echoed(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data, (string)$response->getBody());
        self::assertIsArray($data['query'] ?? null);

        return ['query' => $data['query'], 'body' => is_array($data['body'] ?? null) ? $data['body'] : null];
    }

    /**
     * @param list<string> $freeFields
     */
    protected function signedPayload(string $txSolrQuery, array $freeFields = []): string
    {
        return $this->get(TokenService::class)->encode($txSolrQuery, $freeFields);
    }

    protected function payloadWithSignature(string $txSolrQuery, string $signature): string
    {
        return base64_encode((string)json_encode(['p' => $txSolrQuery, 's' => $signature]));
    }

    protected function assertRedirectsTo(string $location, ResponseInterface $response): void
    {
        self::assertSame(302, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($location, $response->getHeaderLine('Location'));
    }
}
