<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;

final class RangeFacetScriptTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/rangefacets' => 'typo3conf/sites',
    ];

    #[Test]
    public function stockRangeFacetScriptsAreReplacedByThePatchedCopies(): void
    {
        $response = $this->request('/page');
        $html = (string)$response->getBody();
        $version = (new Typo3Version())->getMajorVersion() >= 14 ? 'v14' : 'v13';

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('JavaScript/Solr/' . $version . '/facet_daterange_controller.js', $html);
        self::assertStringContainsString('JavaScript/Solr/' . $version . '/facet_numericrange_controller.js', $html);
        self::assertStringNotContainsString('solr/Resources/Public/JavaScript/facet_', $html);
    }
}
