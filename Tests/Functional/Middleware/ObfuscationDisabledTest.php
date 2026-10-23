<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

final class ObfuscationDisabledTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/disabled' => 'typo3conf/sites',
    ];

    #[Test]
    public function unsignedTxSolrRequestPasses(): void
    {
        self::assertSame(['tx_solr' => ['q' => 'foo']], $this->echoed($this->request('/search?tx_solr%5Bq%5D=foo'))['query']);
    }

    #[Test]
    public function unsignedSuggestRequestPasses(): void
    {
        self::assertSame(200, $this->request('/?type=7384&tx_solr%5BqueryString%5D=fo')->getStatusCode());
    }

    #[Test]
    public function formIsNotRewritten(): void
    {
        $html = (string)$this->request('/form')->getBody();

        self::assertStringContainsString('name="tx_solr[q]"', $html);
        self::assertStringNotContainsString('name="_ss"', $html);
    }
}
