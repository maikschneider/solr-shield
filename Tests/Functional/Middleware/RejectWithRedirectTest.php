<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RejectWithRedirectTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/redirect' => 'typo3conf/sites',
    ];

    #[Test]
    public function rejectedRequestRedirectsToSameSiteReferer(): void
    {
        $response = $this->request('/search?tx_solr%5Bq%5D=foo', null, ['Referer' => 'http://localhost/previous?page=2#top']);

        $this->assertRedirectsTo('http://localhost/previous?page=2', $response);
    }

    #[Test]
    public function rejectedRequestWithoutRefererRedirectsToSiteRoot(): void
    {
        $this->assertRedirectsTo('http://localhost/', $this->request('/search?tx_solr%5Bq%5D=foo'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignReferers(): array
    {
        return [
            'other host' => ['https://evil.example/landing'],
            'other scheme' => ['https://localhost/previous'],
            'other port' => ['http://localhost:8080/previous'],
            'subdomain' => ['http://evil.localhost/previous'],
            'user info' => ['http://localhost@evil.example/previous'],
            'protocol relative' => ['//evil.example/landing'],
            'relative' => ['/previous'],
            'malformed' => ['http:///previous'],
        ];
    }

    #[Test]
    #[DataProvider('foreignReferers')]
    public function rejectedRequestWithForeignRefererRedirectsToSiteRoot(string $referer): void
    {
        $response = $this->request('/search?tx_solr%5Bq%5D=foo', null, ['Referer' => $referer]);

        $this->assertRedirectsTo('http://localhost/', $response);
    }

    #[Test]
    public function signedRequestStillPasses(): void
    {
        $query = $this->echoed($this->request('/search?_ss=' . rawurlencode($this->signedPayload('tx_solr%5Bq%5D=foo'))))['query'];

        self::assertSame(['tx_solr' => ['q' => 'foo']], $query);
    }
}
