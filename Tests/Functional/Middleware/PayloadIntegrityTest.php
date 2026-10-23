<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

/**
 * A client that holds one valid `_ss` value must not be able to turn it into
 * a request for parameters the server did not sign.
 */
final class PayloadIntegrityTest extends AbstractMiddlewareTestCase
{
    #[Test]
    public function signatureOfOneLinkDoesNotSignAnotherPayload(): void
    {
        $forged = $this->withQueryString($this->signedPayload('tx_solr%5Bq%5D=foo'), 'tx_solr%5Bfilter%5D%5B0%5D=type%3Anews');

        $this->assertRedirectsTo('/', $this->request('/search?_ss=' . rawurlencode($forged)));
    }

    #[Test]
    public function rawTxSolrNextToAValidPayloadIsRejected(): void
    {
        $uri = '/search?_ss=' . rawurlencode($this->signedPayload('')) . '&tx_solr%5Bfilter%5D%5B0%5D=type%3Anews';

        $this->assertRedirectsTo('/', $this->request($uri));
    }

    #[Test]
    public function formTokenRejectsFieldsTheFormDoesNotHave(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B1%5D=type%3Anews&_ss=' . rawurlencode($this->formSs('search'));

        $this->assertRedirectsTo('/', $this->request($uri));
    }

    #[Test]
    public function formTokenRejectsChangedHiddenValues(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . rawurlencode($this->formSs('search'));

        $this->assertRedirectsTo('/', $this->request($uri));
    }

    #[Test]
    public function formTokenAcceptsTheFormAsRendered(): void
    {
        $uri = '/search?_s%5Bq%5D=anything&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . rawurlencode($this->formSs('search'));

        self::assertSame(
            ['tx_solr' => ['q' => 'anything', 'filter' => ['type:pages']]],
            $this->echoed($this->request($uri))['query']
        );
    }

    #[Test]
    public function formTokenFillsInOmittedHiddenValues(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_ss=' . rawurlencode($this->formSs('search'));

        self::assertSame(['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], $this->echoed($this->request($uri))['query']);
    }

    #[Test]
    public function appendedFieldsAcceptAnyIndexButKeepTheHiddenValue(): void
    {
        $ss = rawurlencode($this->formSs('facets'));

        self::assertSame(
            ['tx_solr' => ['filter' => ['type:pages', 'author:jane', 'author:joe']]],
            $this->echoed($this->request('/search?_s%5Bfilter%5D%5B0%5D=type%3Apages&_s%5Bfilter%5D%5B1%5D=author%3Ajane&_s%5Bfilter%5D%5B2%5D=author%3Ajoe&_ss=' . $ss))['query']
        );
        $this->assertRedirectsTo('/', $this->request('/search?_s%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . $ss));
        $this->assertRedirectsTo('/', $this->request('/search?_s%5Bfilter%5D%5Bx%5D=type%3Anews&_ss=' . $ss));
    }

    #[Test]
    public function suggestAcceptsOnlyTheTypedQueryAsRawParameter(): void
    {
        $ss = rawurlencode($this->signedPayload(''));

        self::assertSame(200, $this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&_ss=' . $ss)->getStatusCode());
        $this->assertRedirectsTo('/', $this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&tx_solr%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . $ss));
    }

    private function withQueryString(string $ss, string $query): string
    {
        $data = json_decode((string)base64_decode($ss, true), true);
        self::assertIsArray($data);
        $data['p'] = $query;

        return base64_encode((string)json_encode($data));
    }

    private function formSs(string $formId): string
    {
        $html = (string)$this->request('/form')->getBody();
        preg_match('#<form id="' . $formId . '".*?name="_ss" value="([^"]+)"#s', $html, $matches);
        $value = $matches[1] ?? '';
        self::assertNotSame('', $value, $html);

        return html_entity_decode($value, ENT_QUOTES);
    }
}
