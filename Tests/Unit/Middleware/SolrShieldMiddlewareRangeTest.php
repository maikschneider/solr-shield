<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\Middleware;

use MaikSchneider\SolrShield\Middleware\SolrShieldMiddleware;
use MaikSchneider\SolrShield\Service\BotDetectionService;
use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SolrShieldMiddlewareRangeTest extends UnitTestCase
{
    private const TEMPLATE = 'tx_solr%5Bfilter%5D%5B0%5D=created%3A___FROM___-___TO___';

    private mixed $originalEncryptionKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEncryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'encryption-key';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->originalEncryptionKey;
        parent::tearDown();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function openBounds(): array
    {
        return [
            'open start' => [['from' => '', 'to' => '202612312359'], 'created:-202612312359'],
            'open end' => [['from' => '202612010000', 'to' => ''], 'created:202612010000-'],
            'both bounds open' => [['from' => '', 'to' => ''], 'created:-'],
            'negative and decimal bounds' => [['from' => '-5', 'to' => '10.5'], 'created:-5-10.5'],
        ];
    }

    /**
     * @param array<string, string> $range
     */
    #[Test]
    #[DataProvider('openBounds')]
    public function emptyBoundFillsThePlaceholderWithAnEmptyString(array $range, string $expectedFilter): void
    {
        [$response, $handled] = $this->process($range);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tx_solr' => ['filter' => [$expectedFilter]]], $handled?->getQueryParams());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidBounds(): array
    {
        return [
            'space' => [['from' => ' ', 'to' => '5']],
            'whitespace around a number' => [['from' => '5 ', 'to' => '6']],
            'trailing newline' => [['from' => '5', 'to' => "6\n"]],
            'asterisk' => [['from' => '*', 'to' => '5']],
            'sign only' => [['from' => '-', 'to' => '5']],
            'dot only' => [['from' => '5', 'to' => '.']],
            'letters' => [['from' => '5', 'to' => 'abc']],
            'query syntax' => [['from' => '5', 'to' => '10 OR type:news']],
            'unknown key' => [['from' => '5', 'q' => '']],
            'array bound' => [['from' => ['5'], 'to' => '']],
            'no bounds' => [[]],
            'scalar range' => ['5'],
        ];
    }

    #[Test]
    #[DataProvider('invalidBounds')]
    public function anythingElseThanNumbersOrEmptyIsRejected(mixed $range): void
    {
        [$response, $handled] = $this->process($range);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($handled);
    }

    /**
     * @return array{ResponseInterface, ServerRequestInterface|null}
     */
    private function process(mixed $range): array
    {
        $subject = new SolrShieldMiddleware(new TokenService(), new BotDetectionService(), new ResponseFactory());
        $request = (new ServerRequest('https://example.com/search', 'GET'))
            ->withQueryParams(['_ss' => (new TokenService())->encode(self::TEMPLATE), '_sr' => $range]);
        $handler = new class() implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return (new ResponseFactory())->createResponse();
            }
        };

        return [$subject->process($request, $handler), $handler->request];
    }
}
