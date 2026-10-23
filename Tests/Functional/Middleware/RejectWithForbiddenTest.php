<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

final class RejectWithForbiddenTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/forbidden' => 'typo3conf/sites',
    ];

    #[Test]
    public function unsignedTxSolrRequestIsForbidden(): void
    {
        $response = $this->request('/search?tx_solr%5Bq%5D=foo', null, ['Referer' => 'http://localhost/previous']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function forgedSignatureIsForbidden(): void
    {
        $ss = $this->payloadWithSignature('tx_solr%5Bq%5D=foo', 'AAAAAAAAAAAAAAAA');

        self::assertSame(403, $this->request('/search?_ss=' . rawurlencode($ss))->getStatusCode());
    }

    #[Test]
    public function signedRequestStillPasses(): void
    {
        $query = $this->echoed($this->request('/search?_ss=' . rawurlencode($this->signedPayload('tx_solr%5Bq%5D=foo'))))['query'];

        self::assertSame(['tx_solr' => ['q' => 'foo']], $query);
    }
}
