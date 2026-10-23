<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SolrShieldMiddlewareTest extends AbstractMiddlewareTestCase
{
    #[Test]
    public function requestWithoutSolrParametersPassesUnchanged(): void
    {
        self::assertSame(['L' => '0'], $this->echoed($this->request('/search?L=0'))['query']);
    }

    #[Test]
    public function signedPayloadIsDecodedIntoTxSolrParameters(): void
    {
        $ss = $this->signedPayload('tx_solr%5Bq%5D=foo&tx_solr%5Bfilter%5D%5B0%5D=type%3Apages');

        $query = $this->echoed($this->request('/search?L=0&_ss=' . rawurlencode($ss)))['query'];

        self::assertSame(['L' => '0', 'tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], $query);
    }

    #[Test]
    public function signedEmptyPayloadPassesWithoutTxSolrParameters(): void
    {
        $query = $this->echoed($this->request('/search?_ss=' . rawurlencode($this->signedPayload(''))))['query'];

        self::assertSame([], $query);
    }

    #[Test]
    public function signedPayloadInPostBodyIsDecoded(): void
    {
        $echoed = $this->echoed($this->request('/search', ['_ss' => $this->signedPayload('tx_solr%5Bq%5D=foo'), 'other' => '1']));

        self::assertSame(['tx_solr' => ['q' => 'foo']], $echoed['query']);
        self::assertSame(['other' => '1'], $echoed['body']);
    }

    #[Test]
    public function obfuscatedFormFieldsAreMappedBackToTxSolr(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . rawurlencode($this->signedPayload('tx_solr%5Bfilter%5D%5B0%5D=type%3Apages', ['[q]']));

        $query = $this->echoed($this->request($uri))['query'];

        self::assertSame(['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], $query);
    }

    #[Test]
    public function obfuscatedFormFieldsInPostBodyAreMappedBackToTxSolr(): void
    {
        $echoed = $this->echoed($this->request('/search', ['_s' => ['q' => 'foo'], '_ss' => $this->signedPayload('', ['[q]'])]));

        self::assertSame(['tx_solr' => ['q' => 'foo']], $echoed['body']);
    }

    #[Test]
    public function signedSuggestRequestPasses(): void
    {
        $response = $this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&_ss=' . rawurlencode($this->signedPayload('')));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function signedSuggestRequestKeepsTypedQueryAndDecodesFilters(): void
    {
        $ss = $this->signedPayload('tx_solr%5BadditionalFilters%5D%5B0%5D=type%3Apages');

        $query = $this->echoed($this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&_ss=' . rawurlencode($ss)))['query'];

        self::assertSame(['type' => '7384', 'tx_solr' => ['queryString' => 'fo', 'additionalFilters' => ['type:pages']]], $query);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedRequests(): array
    {
        return [
            'unsigned tx_solr' => ['/search?tx_solr%5Bq%5D=foo'],
            'unsigned tx_solr filter' => ['/search?tx_solr%5Bfilter%5D%5B0%5D=type%3Apages'],
            'obfuscated form fields without _ss' => ['/search?_s%5Bq%5D=foo'],
            'unsigned suggest' => ['/?type=7384&tx_solr%5BqueryString%5D=fo'],
            'unsigned suggest without parameters' => ['/?type=7384'],
            'empty _ss' => ['/search?_ss='],
            '_ss not base64' => ['/search?_ss=%21%21%21'],
            '_ss as array' => ['/search?_ss%5B%5D=x&tx_solr%5Bq%5D=foo'],
            '_ss not JSON' => ['/search?_ss=' . rawurlencode(base64_encode('foo'))],
            '_ss without signature' => ['/search?_ss=' . rawurlencode(base64_encode('{"p":"tx_solr%5Bq%5D=foo"}'))],
        ];
    }

    #[Test]
    #[DataProvider('rejectedRequests')]
    public function rejectedRequestRedirectsToReferer(string $uri): void
    {
        $response = $this->request($uri, null, ['Referer' => 'http://localhost/previous']);

        $this->assertRedirectsTo('http://localhost/previous', $response);
    }

    #[Test]
    public function signedPayloadWithoutQueryStringIsRejected(): void
    {
        $ss = base64_encode((string)json_encode(['s' => 'AAAAAAAAAAAAAAAA']));

        $this->assertRedirectsTo('/', $this->request('/search?_ss=' . rawurlencode($ss)));
    }

    #[Test]
    public function signedPayloadWithNonStringQueryStringIsRejected(): void
    {
        $ss = base64_encode((string)json_encode(['p' => ['tx_solr' => ['q' => 'foo']], 's' => 'AAAAAAAAAAAAAAAA']));

        $this->assertRedirectsTo('/', $this->request('/search?_ss=' . rawurlencode($ss)));
    }

    #[Test]
    public function rejectedRequestWithoutRefererRedirectsToRoot(): void
    {
        $this->assertRedirectsTo('/', $this->request('/search?tx_solr%5Bq%5D=foo'));
    }

    #[Test]
    public function payloadWithForgedSignatureIsRejected(): void
    {
        $ss = $this->payloadWithSignature('tx_solr%5Bq%5D=foo', 'AAAAAAAAAAAAAAAA');

        $this->assertRedirectsTo('/', $this->request('/search?_ss=' . rawurlencode($ss)));
    }

    #[Test]
    public function unsignedTxSolrInPostBodyIsRejected(): void
    {
        $this->assertRedirectsTo('/', $this->request('/search', ['tx_solr' => ['q' => 'foo']]));
    }
}
