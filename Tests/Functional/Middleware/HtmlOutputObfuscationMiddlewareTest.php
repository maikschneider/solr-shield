<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame(15, substr_count($html, 'name="_ss"'));
        self::assertMatchesRegularExpression('#<form id="search".*name="_ss".*</form>#s', explode('<form id="newsletter"', $html)[0]);
    }

    #[Test]
    public function injectedTokenSignsHiddenValuesAndFreeFieldsOfItsForm(): void
    {
        self::assertSame(
            ['params' => ['tx_solr' => ['filter' => ['type:pages']]], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode($this->formSs('search'))
        );
    }

    #[Test]
    public function hiddenFieldsAreRecognizedLikeTheBrowserParsesThem(): void
    {
        self::assertSame(
            [
                'params' => ['tx_solr' => ['filter' => ['type:pages', 'author:jane', 'year:2026', 'lang:en']]],
                'freeFields' => ['[q]'],
            ],
            $this->get(TokenService::class)->decode($this->formSs('markup'))
        );
    }

    #[Test]
    public function customElementsAreNoControls(): void
    {
        self::assertSame(
            ['params' => ['tx_solr' => ['filter' => ['type:pages']]], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode($this->formSs('custom-elements'))
        );
    }

    #[Test]
    public function formInsideACustomElementOwnsItsLinkedFields(): void
    {
        self::assertSame(
            ['params' => [], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode($this->formSs('wrapped'))
        );
    }

    #[Test]
    public function formWithAGreaterThanSignInAQuotedAttributeOwnsItsLinkedFields(): void
    {
        self::assertSame(
            ['params' => [], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode($this->formSs('quoted'))
        );
    }

    #[Test]
    public function formMarkupInACommentOrScriptOwnsNoFields(): void
    {
        $html = (string)$this->request('/form')->getBody();

        self::assertStringContainsString('<!-- <form id="commented"></form> -->', $html);
        self::assertStringContainsString('<script>const note = \'<form id="commented"></form>\';</script>', $html);
        self::assertSame(
            ['params' => [], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode($this->formSs('commented'))
        );
    }

    #[Test]
    public function tokenOfAFormInADisabledFieldsetIsSubmittedWithItsLinkedFields(): void
    {
        $html = (string)$this->request('/form')->getBody();

        self::assertSame(1, preg_match('#<form id="disabled-form" action="/search" method="get"></form></fieldset>\s*<input type="hidden" name="_ss" value="([^"]+)" form="disabled-form"><input type="text" name="_s\[q\]" value="" form="disabled-form">#', $html, $matches), $html);
        self::assertSame(
            ['params' => [], 'freeFields' => ['[q]']],
            $this->get(TokenService::class)->decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES))
        );
    }

    #[Test]
    public function largeInlineContentDoesNotPreventSigning(): void
    {
        $html = (string)$this->request('/large-form')->getBody();

        self::assertStringContainsString('<input type="text" name="_s[q]"><input type="hidden" name="_ss" value="', $html);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function fallbackSubmissions(): array
    {
        return [
            'checkbox checked' => ['type:news', ['tx_solr' => ['filter' => ['type:news']]]],
            'checkbox unchecked' => ['', ['tx_solr' => ['filter' => ['']]]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('fallbackSubmissions')]
    public function checkboxCanReplaceItsHiddenFallbackValue(string $value, array $expected): void
    {
        $uri = '/search?_s%5Bfilter%5D%5B0%5D=' . rawurlencode($value) . '&_ss=' . rawurlencode($this->formSs('fallback'));

        self::assertSame($expected, $this->echoed($this->request($uri))['query']);
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
        $uri = '/search?_s%5Bq%5D=foo&_s%5Bfilter%5D%5B0%5D=type%3Apages&_ss=' . rawurlencode($this->formSs('search'));

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

    #[Test]
    public function suggestUrlIsSignedWithAnEmptyPayload(): void
    {
        parse_str((string)parse_url($this->suggestUrl('search'), PHP_URL_QUERY), $query);

        self::assertSame('7384', $query['type'] ?? null);
        self::assertSame('0', $query['L'] ?? null);
        self::assertIsString($query['_ss'] ?? null);
        self::assertSame(['params' => [], 'freeFields' => []], $this->get(TokenService::class)->decode($query['_ss']));
    }

    #[Test]
    public function suggestUrlThatAlreadyCarriesSsIsKept(): void
    {
        self::assertSame('/?type=7384&_ss=FILTERS', $this->suggestUrl('filtered'));
    }

    #[Test]
    public function autocompleteRequestToTheRewrittenSuggestUrlPasses(): void
    {
        $query = $this->echoed($this->request($this->suggestUrl('search') . '&tx_solr%5BqueryString%5D=fo'))['query'];

        self::assertSame(['type' => '7384', 'L' => '0', 'tx_solr' => ['queryString' => 'fo']], $query);
    }

    private function suggestUrl(string $formId): string
    {
        $html = (string)$this->request('/form')->getBody();
        preg_match('/<form id="' . $formId . '"[^>]*\sdata-suggest="([^"]+)"/', $html, $matches);
        $value = $matches[1] ?? '';
        self::assertNotSame('', $value, $html);

        return html_entity_decode($value, ENT_QUOTES);
    }
}
