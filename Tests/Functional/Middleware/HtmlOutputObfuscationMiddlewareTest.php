<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\Test;

final class HtmlOutputObfuscationMiddlewareTest extends AbstractMiddlewareTestCase
{
    #[Test]
    public function txSolrFieldNamesAreRenamed(): void
    {
        $html = (string)$this->request('/form')->getBody();

        self::assertStringNotContainsString('tx_solr[', $html);
        self::assertStringContainsString('name="_s[q]"', $html);
        self::assertStringContainsString("name='_s[filter][0]'", $html);
    }

    #[Test]
    public function signedTokenIsInjectedIntoSolrFormsOnly(): void
    {
        $html = (string)$this->request('/form')->getBody();

        self::assertSame(1, substr_count($html, 'name="_ss"'));
        self::assertMatchesRegularExpression('#<form id="search".*name="_ss".*</form>#s', explode('<form id="newsletter"', $html)[0]);
    }

    #[Test]
    public function injectedTokenIsASignedEmptyPayload(): void
    {
        $data = json_decode((string)base64_decode($this->injectedSs(), true), true);

        self::assertSame(['p' => '', 's' => $this->get(TokenService::class)->generateToken()], $data);
    }

    #[Test]
    public function contentLengthMatchesRewrittenBody(): void
    {
        $response = $this->request('/form');

        self::assertSame((string)strlen((string)$response->getBody()), $response->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function submittingTheRewrittenFormReachesThePageAsTxSolr(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . rawurlencode($this->injectedSs());

        $query = $this->echoed($this->request($uri))['query'];

        self::assertSame(['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], $query);
    }

    #[Test]
    public function nonHtmlResponsesAreNotRewritten(): void
    {
        $ss = $this->signedPayload('tx_solr%5Bq%5D=name%3D%22tx_solr%5B');

        $query = $this->echoed($this->request('/search?_ss=' . rawurlencode($ss)))['query'];

        self::assertSame(['tx_solr' => ['q' => 'name="tx_solr[']], $query);
    }

    private function injectedSs(): string
    {
        $html = (string)$this->request('/form')->getBody();
        preg_match('/name="_ss" value="([^"]+)"/', $html, $matches);
        $value = $matches[1] ?? '';
        self::assertNotSame('', $value, $html);

        return html_entity_decode($value, ENT_QUOTES);
    }
}
