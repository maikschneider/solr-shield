<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RangeFacetScriptTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/rangefacets' => 'typo3conf/sites',
    ];

    private static function patchedScript(string $script): string
    {
        return 'JavaScript/Solr/' . ((new Typo3Version())->getMajorVersion() >= 14 ? 'v14' : 'v13') . '/' . $script;
    }

    #[Test]
    public function stockRangeFacetScriptsAreReplacedByThePatchedCopies(): void
    {
        $response = $this->request('/page');
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString(self::patchedScript('facet_daterange_controller.js'), $html);
        self::assertStringContainsString(self::patchedScript('facet_numericrange_controller.js'), $html);
        self::assertStringNotContainsString('solr/Resources/Public/JavaScript/facet_', $html);
    }

    #[Test]
    public function integrityHashIsRecomputedForThePatchedCopy(): void
    {
        $html = (string)$this->request('/page')->getBody();
        $patched = (string)file_get_contents(GeneralUtility::getFileAbsFileName(
            'EXT:solr_shield/Resources/Public/' . self::patchedScript('facet_daterange_controller.js')
        ));

        self::assertStringNotContainsString('sha384-stock', $html);
        self::assertStringContainsString('integrity="sha384-' . base64_encode(hash('sha384', $patched, true)) . '"', $html);
    }
}
