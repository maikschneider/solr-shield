<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\Service;

use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TokenServiceTest extends UnitTestCase
{
    private const QUERY = 'tx_solr%5Bq%5D=foo&tx_solr%5Bfilter%5D%5B0%5D=type%3Apages';

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
     * @return array<string, mixed>
     */
    private static function data(string $encoded): array
    {
        $data = json_decode((string)base64_decode($encoded, true), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeRaw(array $data): string
    {
        return base64_encode((string)json_encode($data));
    }

    #[Test]
    public function encodedValueRoundTrips(): void
    {
        $subject = new TokenService();

        self::assertSame(
            ['params' => ['tx_solr' => ['q' => 'foo', 'filter' => ['type:pages']]], 'freeFields' => []],
            $subject->decode($subject->encode(self::QUERY))
        );
    }

    #[Test]
    public function freeFieldsRoundTrip(): void
    {
        $subject = new TokenService();

        self::assertSame(
            ['params' => [], 'freeFields' => ['[q]', '[filter][]']],
            $subject->decode($subject->encode('', ['[q]', '[filter][]']))
        );
    }

    #[Test]
    public function signatureIsSixteenBase64UrlCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16}$/', self::data((new TokenService())->encode(self::QUERY))['s']);
    }

    #[Test]
    public function encodingIsDeterministic(): void
    {
        self::assertSame((new TokenService())->encode(self::QUERY), (new TokenService())->encode(self::QUERY));
    }

    #[Test]
    public function signatureCoversTheQuery(): void
    {
        $data = self::data((new TokenService())->encode(self::QUERY));
        $data['p'] = 'tx_solr%5Bfilter%5D%5B0%5D=type%3Anews';

        self::assertNull((new TokenService())->decode(self::encodeRaw($data)));
    }

    #[Test]
    public function signatureCoversTheFreeFields(): void
    {
        $data = self::data((new TokenService())->encode('', ['[q]']));
        $data['f'] = ['[q]', '[filter][]'];

        self::assertNull((new TokenService())->decode(self::encodeRaw($data)));
    }

    #[Test]
    public function signatureDependsOnEncryptionKey(): void
    {
        $encoded = (new TokenService())->encode(self::QUERY);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'rotated-encryption-key';

        self::assertNull((new TokenService())->decode($encoded));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValues(): array
    {
        return [
            'empty' => [''],
            'not base64' => ['!!!'],
            'not JSON' => [base64_encode('foo')],
            'no signature' => [self::encodeRaw(['p' => ''])],
            'no query' => [self::encodeRaw(['s' => 'AAAAAAAAAAAAAAAA'])],
            'query not a string' => [self::encodeRaw(['p' => ['q' => 'foo'], 's' => 'AAAAAAAAAAAAAAAA'])],
            'free fields not a list' => [self::encodeRaw(['p' => '', 'f' => ['a' => '[q]'], 's' => 'AAAAAAAAAAAAAAAA'])],
            'free fields not strings' => [self::encodeRaw(['p' => '', 'f' => [1], 's' => 'AAAAAAAAAAAAAAAA'])],
            'wrong signature' => [self::encodeRaw(['p' => '', 's' => 'AAAAAAAAAAAAAAAA'])],
        ];
    }

    #[Test]
    #[DataProvider('malformedValues')]
    public function malformedValuesAreRejected(string $value): void
    {
        self::assertNull((new TokenService())->decode($value));
    }
}
