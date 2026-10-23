<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;

final class RangeFacetScriptVersionedFilenameTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/rangefacets' => 'typo3conf/sites',
    ];

    protected array $configurationToUseInTestInstance = [
        'FE' => ['versionNumberInFilename' => true],
    ];

    #[Test]
    public function scriptsWithTheVersionNumberInTheFilenameAreReplaced(): void
    {
        $version = (new Typo3Version())->getMajorVersion() >= 14 ? 'v14' : 'v13';

        $html = (string)$this->request('/page')->getBody();

        self::assertStringContainsString('JavaScript/Solr/' . $version . '/facet_numericrange_controller', $html);
        self::assertStringNotContainsString('solr/Resources/Public/JavaScript/facet_', $html);
    }
}
