<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertForbidden($this->request('/search?_ss=' . rawurlencode($forged)));
    }

    #[Test]
    public function rawTxSolrNextToAValidPayloadIsRejected(): void
    {
        $uri = '/search?_ss=' . rawurlencode($this->signedPayload('')) . '&tx_solr%5Bfilter%5D%5B0%5D=type%3Anews';

        $this->assertForbidden($this->request($uri));
    }

    #[Test]
    public function formTokenRejectsFieldsTheFormDoesNotHave(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B1%5D=type%3Anews&_ss=' . rawurlencode($this->formSs('search'));

        $this->assertForbidden($this->request($uri));
    }

    #[Test]
    public function formTokenRejectsChangedHiddenValues(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . rawurlencode($this->formSs('search'));

        $this->assertForbidden($this->request($uri));
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
        $this->assertForbidden($this->request('/search?_s%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . $ss));
        $this->assertForbidden($this->request('/search?_s%5Bfilter%5D%5Bx%5D=type%3Anews&_ss=' . $ss));
    }

    #[Test]
    public function markupInsideATextareaIsNoControl(): void
    {
        $ss = rawurlencode($this->formSs('textarea'));

        self::assertSame(
            ['tx_solr' => ['q' => '<input name="x">', 'filter' => ['type:pages']]],
            $this->echoed($this->request('/search?_s%5Bq%5D=%3Cinput%20name%3D%22x%22%3E&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . $ss))['query']
        );
        $this->assertForbidden($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_s%5Bfilter%5D%5B1%5D=type%3Anews&_ss=' . $ss));
    }

    #[Test]
    public function customElementsDoNotHideOrAddControls(): void
    {
        $ss = rawurlencode($this->formSs('custom-elements'));

        self::assertSame(
            ['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]],
            $this->echoed($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . $ss))['query']
        );
        $this->assertForbidden($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . $ss));
        $this->assertForbidden($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_s%5Bfilter%5D%5B2%5D=type%3Anews&_ss=' . $ss));
    }

    #[Test]
    public function fieldsLinkedThroughTheFormAttributeAreSigned(): void
    {
        $ss = rawurlencode($this->formSs('attached'));

        self::assertSame(
            ['tx_solr' => ['q' => 'foo', 'filter' => ['']]],
            $this->echoed($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B%5D=&_ss=' . $ss))['query']
        );
        $this->assertForbidden($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B%5D=type%3Anews&_ss=' . $ss));
        $this->assertForbidden($this->request('/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B%5D=&_s%5Bsort%5D=title&_ss=' . $ss));
    }

    #[Test]
    public function fieldsBelongToTheFormTheirFormAttributeNames(): void
    {
        $data = json_decode((string)base64_decode($this->formSs('attached'), true), true);

        self::assertSame(['p' => 'tx_solr%5Bfilter%5D%5B%5D=', 'f' => ['[q]']], array_diff_key((array)$data, ['s' => true]));
    }

    #[Test]
    public function fieldsLinkedThroughTheFormAttributeKeepTheDocumentOrder(): void
    {
        $ss = rawurlencode($this->formSs('ordered'));
        $filters = ['lang:en', 'type:news', 'type:pages', 'year:2026', 'author:jane'];
        $query = implode('&', array_map(static fn (string $filter): string => '_s%5Bfilter%5D%5B%5D=' . rawurlencode($filter), $filters));

        self::assertSame(['tx_solr' => ['filter' => $filters]], $this->echoed($this->request('/search?' . $query . '&_ss=' . $ss))['query']);
        $this->assertForbidden($this->request('/search?_s%5Bfilter%5D%5B%5D=type%3Anews&_s%5Bfilter%5D%5B%5D=lang%3Aen&_ss=' . $ss));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formsWithUnsubmittedFields(): array
    {
        return [
            'template' => ['templated'],
            'iframe with a quoted end tag' => ['framed'],
            'datalist' => ['datalisted'],
            'other element with the id first' => ['shadowed'],
        ];
    }

    #[Test]
    #[DataProvider('formsWithUnsubmittedFields')]
    public function fieldsTheBrowserDoesNotSubmitCannotUnlockAHiddenValue(string $formId): void
    {
        $ss = rawurlencode($this->formSs($formId));

        self::assertSame(['tx_solr' => ['filter' => 'type:news']], $this->echoed($this->request('/search?_s%5Bfilter%5D=type%3Anews&_ss=' . $ss))['query']);
        $this->assertForbidden($this->request('/search?_s%5Bfilter%5D=type%3Apages&_ss=' . $ss));
        $this->assertForbidden($this->request('/search?_s%5Bfilter%5D=type%3Anews&_s%5Bq%5D=foo&_ss=' . $ss));
    }

    #[Test]
    public function fieldsInADisabledFieldsetAreNotRestored(): void
    {
        $uri = '/search?_s%5Bq%5D=foo&_ss=' . rawurlencode($this->formSs('fieldset'));

        self::assertSame(['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages', 'type:event', 'year:2026']]], $this->echoed($this->request($uri))['query']);
    }

    #[Test]
    public function suggestAcceptsOnlyTheTypedQueryAsRawParameter(): void
    {
        $ss = rawurlencode($this->signedPayload(''));

        self::assertSame(200, $this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&_ss=' . $ss)->getStatusCode());
        $this->assertForbidden($this->request('/?type=7384&tx_solr%5BqueryString%5D=fo&tx_solr%5Bfilter%5D%5B0%5D=type%3Anews&_ss=' . $ss));
    }

    #[Test]
    public function rangeBoundsFillTheSignedTemplate(): void
    {
        $ss = rawurlencode($this->signedPayload('tx_solr%5Bfilter%5D%5B0%5D=uidRange%3A___FROM___-___TO___&tx_solr%5Bq%5D=foo'));

        self::assertSame(
            ['tx_solr' => ['filter' => ['uidRange:5-10.5'], 'q' => 'foo']],
            $this->echoed($this->request('/search?_ss=' . $ss . '&_sr%5Bfrom%5D=5&_sr%5Bto%5D=10.5'))['query']
        );
    }

    #[Test]
    public function rangeBoundsAcceptOnlyNumbers(): void
    {
        $ss = rawurlencode($this->signedPayload('tx_solr%5Bfilter%5D%5B0%5D=uidRange%3A___FROM___-___TO___'));

        $this->assertForbidden($this->request('/search?_ss=' . $ss . '&_sr%5Bfrom%5D=5&_sr%5Bto%5D=10%20OR%20type%3Anews'));
        $this->assertForbidden($this->request('/search?_ss=' . $ss . '&_sr%5Bfrom%5D=5&_sr%5Bq%5D=10'));
        $this->assertForbidden($this->request('/search?_ss=' . $ss . '&_sr=5'));
    }

    private function withQueryString(string $ss, string $query): string
    {
        $data = json_decode((string)base64_decode($ss, true), true);
        self::assertIsArray($data);
        $data['p'] = $query;

        return base64_encode((string)json_encode($data));
    }
}
