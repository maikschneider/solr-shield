<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\EventListener;

use MaikSchneider\SolrShield\Service\TokenService;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

abstract class ListenerTestCase extends UnitTestCase
{
    private mixed $originalEncryptionKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEncryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'encryption-key';
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->originalEncryptionKey;
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    protected function disableObfuscationForCurrentRequest(): void
    {
        $site = new Site('main', 1, [
            'base' => 'http://localhost/',
            'settings' => ['solrShield' => ['uriObfuscation' => ['enabled' => false]]],
        ]);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('http://localhost/'))->withAttribute('site', $site);
    }

    /**
     * @return array{query: array<array-key, mixed>, payload: array<array-key, mixed>}
     */
    protected static function decodeUrl(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('_ss', $query, $url);
        self::assertIsString($query['_ss']);

        $decoded = (new TokenService())->decode($query['_ss']);
        self::assertNotNull($decoded, 'invalid signature');

        return ['query' => $query, 'payload' => $decoded['params']];
    }
}
