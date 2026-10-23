<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\EventListener;

use MaikSchneider\SolrShield\EventListener\AfterLinkIsGeneratedEventListener;
use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Event\AfterLinkIsGeneratedEvent;
use TYPO3\CMS\Frontend\Typolink\LinkResult;

final class AfterLinkIsGeneratedEventListenerTest extends ListenerTestCase
{
    private function process(string $url): string
    {
        $event = new AfterLinkIsGeneratedEvent(
            new LinkResult('page', $url),
            $this->createMock(ContentObjectRenderer::class),
            []
        );
        (new AfterLinkIsGeneratedEventListener(new TokenService()))($event);

        return $event->getLinkResult()->getUrl();
    }

    #[Test]
    public function txSolrParametersAreMovedIntoSignedPayload(): void
    {
        $url = $this->process('/search?tx_solr%5Bq%5D=foo&tx_solr%5Bfilter%5D%5B0%5D=type%3Apages');
        $decoded = self::decodeUrl($url);

        self::assertStringStartsWith('/search?_ss=', $url);
        self::assertSame(['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], $decoded['payload']);
    }

    #[Test]
    public function otherParametersAreKept(): void
    {
        $decoded = self::decodeUrl($this->process('https://example.com/search?L=1&tx_solr%5Bq%5D=foo'));

        self::assertSame('1', $decoded['query']['L'] ?? null);
        self::assertSame(['tx_solr' => ['q' => 'foo']], $decoded['payload']);
    }

    #[Test]
    public function linkWithoutTxSolrIsUnchanged(): void
    {
        self::assertSame('/search?L=1', $this->process('/search?L=1'));
        self::assertSame('/imprint', $this->process('/imprint'));
    }

    #[Test]
    public function routingTemplateLinkWithPlaceholderIsUnchanged(): void
    {
        $url = '/search?tx_solr%5Bfilter%5D%5B0%5D=%23%23%23tx_solr%3Afilter%3A0%23%23%23&tx_solr%5Bq%5D=foo';

        self::assertSame($url, $this->process($url));
    }

    #[Test]
    public function linkIsUnchangedWhenObfuscationIsDisabled(): void
    {
        $this->disableObfuscationForCurrentRequest();

        self::assertSame('/search?tx_solr%5Bq%5D=foo', $this->process('/search?tx_solr%5Bq%5D=foo'));
    }
}
