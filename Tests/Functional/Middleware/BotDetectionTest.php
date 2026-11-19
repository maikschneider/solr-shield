<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

final class BotDetectionTest extends AbstractMiddlewareTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/solr_shield/Tests/Functional/Middleware/Fixtures/Sites/botdetection' => 'typo3conf/sites',
    ];

    /**
     * @param array<string, mixed> $overrides
     */
    private static function botPayload(array $overrides = []): string
    {
        return base64_encode((string)json_encode(array_merge([
            'webdriver' => false,
            'screenWidth' => 1920,
            'screenHeight' => 1080,
            'viewportWidth' => 1280,
            'viewportHeight' => 800,
            'elapsed' => 12,
            'mouseMoveCount' => 30,
            'touchCount' => 0,
            'scrollCount' => 4,
            'keyCount' => 6,
            'ts' => time() * 1000,
        ], $overrides)));
    }

    private function signedUri(string $txSolrQuery, ?string $botPayload): string
    {
        $uri = '/search?_ss=' . rawurlencode($this->signedPayload($txSolrQuery));

        return $botPayload === null ? $uri : $uri . '&_solr_bot=' . rawurlencode($botPayload);
    }

    #[Test]
    public function signedRequestWithHumanPayloadPassesWithoutTheBotParameter(): void
    {
        $query = $this->echoed($this->request($this->signedUri('tx_solr%5Bq%5D=foo', self::botPayload())))['query'];

        self::assertSame(['tx_solr' => ['q' => 'foo']], $query);
    }

    #[Test]
    public function signedRequestWithoutPayloadIsRejected(): void
    {
        $this->assertForbidden($this->request($this->signedUri('tx_solr%5Bq%5D=foo', null)));
    }

    #[Test]
    public function signedRequestWithBotPayloadIsRejected(): void
    {
        $this->assertForbidden($this->request($this->signedUri('tx_solr%5Bq%5D=foo', self::botPayload(['webdriver' => true]))));
    }

    #[Test]
    public function signedRequestWithMalformedPayloadIsRejected(): void
    {
        $this->assertForbidden($this->request($this->signedUri('tx_solr%5Bq%5D=foo', base64_encode('{"screenWidth":"wide","ts":[]}'))));
    }

    #[Test]
    public function formSubmissionWithPayloadInBodyPasses(): void
    {
        $echoed = $this->echoed($this->request('/search', [
            '_s' => ['q' => 'foo'],
            '_ss' => $this->signedPayload(''),
            '_solr_bot' => self::botPayload(),
        ]));

        self::assertSame(['tx_solr' => ['q' => 'foo']], $echoed['body']);
    }

    #[Test]
    public function formSubmissionPrefersThePayloadInTheBodyOverTheOneInTheUrl(): void
    {
        $echoed = $this->echoed($this->request($this->signedUri('tx_solr%5Bq%5D=bar', self::botPayload(['webdriver' => true])), [
            '_s' => ['q' => 'foo'],
            '_ss' => $this->signedPayload(''),
            '_solr_bot' => self::botPayload(),
        ]));

        self::assertSame(['tx_solr' => ['q' => 'foo']], $echoed['body']);
    }

    private function suggestUri(?string $botPayload): string
    {
        $uri = '/?type=7384&tx_solr%5BqueryString%5D=fo&_ss=' . rawurlencode($this->signedPayload(''));

        return $botPayload === null ? $uri : $uri . '&_solr_bot=' . rawurlencode($botPayload);
    }

    #[Test]
    public function suggestRequestWithHumanPayloadPassesWithoutTheBotParameter(): void
    {
        $query = $this->echoed($this->request($this->suggestUri(self::botPayload())))['query'];

        self::assertSame(['type' => '7384', 'tx_solr' => ['queryString' => 'fo']], $query);
    }

    #[Test]
    public function suggestRequestWithoutPayloadIsRejected(): void
    {
        $this->assertForbidden($this->request($this->suggestUri(null)));
    }

    #[Test]
    public function suggestRequestWithBotPayloadIsRejected(): void
    {
        $this->assertForbidden($this->request($this->suggestUri(self::botPayload(['webdriver' => true]))));
    }

    #[Test]
    public function suggestRequestSkipsTheMinimumFormTimeout(): void
    {
        // Passes exactly securityLevel (7) checks only if the timeout check is skipped.
        $payload = self::botPayload(['elapsed' => 0, 'mouseMoveCount' => 0, 'scrollCount' => 0, 'viewportHeight' => 0]);

        self::assertSame(200, $this->request($this->suggestUri($payload))->getStatusCode());
        $this->assertForbidden($this->request($this->signedUri('tx_solr%5Bq%5D=foo', $payload)));
    }

    #[Test]
    public function requestWithoutSolrParametersNeedsNoPayload(): void
    {
        self::assertSame(['L' => '0'], $this->echoed($this->request('/search?L=0'))['query']);
    }

    #[Test]
    public function scriptIsIncludedInRenderedPages(): void
    {
        $response = $this->request('/page');

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('#<script src="[^"]*solr-shield\.js[^"]*" defer="defer"#', (string)$response->getBody());
    }
}
