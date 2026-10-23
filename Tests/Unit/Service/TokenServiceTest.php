<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\Service;

use MaikSchneider\SolrShield\Service\TokenService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TokenServiceTest extends UnitTestCase
{
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

    #[Test]
    public function tokenIsSixteenBase64UrlCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16}$/', (new TokenService())->generateToken());
    }

    #[Test]
    public function tokenIsDeterministic(): void
    {
        self::assertSame((new TokenService())->generateToken(), (new TokenService())->generateToken());
    }

    #[Test]
    public function tokenDependsOnEncryptionKey(): void
    {
        $token = (new TokenService())->generateToken();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'other-encryption-key';

        self::assertNotSame($token, (new TokenService())->generateToken());
    }

    #[Test]
    public function generatedTokenIsValid(): void
    {
        $subject = new TokenService();

        self::assertTrue($subject->isValidToken($subject->generateToken()));
    }

    #[Test]
    public function tokenIsInvalidAfterEncryptionKeyRotation(): void
    {
        $token = (new TokenService())->generateToken();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'rotated-encryption-key';

        self::assertFalse((new TokenService())->isValidToken($token));
    }

    #[Test]
    public function arbitraryStringsAreInvalid(): void
    {
        $subject = new TokenService();

        self::assertFalse($subject->isValidToken(''));
        self::assertFalse($subject->isValidToken('AAAAAAAAAAAAAAAA'));
        self::assertFalse($subject->isValidToken($subject->generateToken() . 'x'));
    }
}
