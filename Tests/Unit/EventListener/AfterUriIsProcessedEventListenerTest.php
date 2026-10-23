<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\EventListener;

use ApacheSolrForTypo3\Solr\Event\Routing\AfterUriIsProcessedEvent;
use MaikSchneider\SolrShield\EventListener\AfterUriIsProcessedEventListener;
use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Uri;

final class AfterUriIsProcessedEventListenerTest extends ListenerTestCase
{
    private function process(string $uri): string
    {
        $event = new AfterUriIsProcessedEvent(new Uri($uri), []);
        (new AfterUriIsProcessedEventListener(new TokenService()))($event);

        return (string)$event->getUri();
    }

    #[Test]
    public function txSolrParametersAreMovedIntoSignedPayload(): void
    {
        $url = $this->process('https://example.com/search?tx_solr%5Bq%5D=foo&tx_solr%5Bfilter%5D%5B0%5D=type%3Apages&tx_solr%5Bpage%5D=2');
        $decoded = self::decodeUrl($url);

        self::assertStringNotContainsString('tx_solr', $url);
        self::assertSame(['_ss'], array_keys($decoded['query']));
        self::assertSame(
            ['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages'], 'page' => '2']],
            $decoded['payload']
        );
    }

    #[Test]
    public function otherParametersAreKept(): void
    {
        $decoded = self::decodeUrl($this->process('https://example.com/search?L=1&tx_solr%5Bq%5D=foo'));

        self::assertSame('1', $decoded['query']['L'] ?? null);
        self::assertSame(['tx_solr' => ['q' => 'foo']], $decoded['payload']);
    }

    #[Test]
    public function pathIsKept(): void
    {
        self::assertStringStartsWith('https://example.com/en/search?_ss=', $this->process('https://example.com/en/search?tx_solr%5Bq%5D=foo'));
    }

    #[Test]
    public function uriWithoutTxSolrIsUnchanged(): void
    {
        self::assertSame('https://example.com/search?L=1', $this->process('https://example.com/search?L=1'));
        self::assertSame('https://example.com/search', $this->process('https://example.com/search'));
    }

    #[Test]
    public function uriIsUnchangedWhenObfuscationIsDisabled(): void
    {
        $this->disableObfuscationForCurrentRequest();
        $uri = 'https://example.com/search?tx_solr%5Bq%5D=foo';

        self::assertSame($uri, $this->process($uri));
    }
}
