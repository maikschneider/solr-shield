<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Tests\Unit\Service;

use MaikSchneider\SolrShield\Service\BotDetectionService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BotDetectionServiceTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private static function payload(array $overrides = []): string
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

    #[Test]
    public function humanPayloadPasses(): void
    {
        self::assertTrue((new BotDetectionService())->isHuman(self::payload()));
    }

    #[Test]
    public function payloadThatIsNotBase64Fails(): void
    {
        self::assertFalse((new BotDetectionService())->isHuman('not base64!'));
    }

    #[Test]
    public function payloadThatIsNotAJsonObjectFails(): void
    {
        self::assertFalse((new BotDetectionService())->isHuman(base64_encode('"just a string"')));
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        self::assertFalse((new BotDetectionService())->isHuman(base64_encode('{}')));
    }

    #[Test]
    public function headlessClientWithoutInteractionFails(): void
    {
        $payload = self::payload([
            'webdriver' => true,
            'screenWidth' => 800,
            'screenHeight' => 600,
            'elapsed' => 0,
            'mouseMoveCount' => 0,
            'scrollCount' => 0,
            'keyCount' => 0,
        ]);

        self::assertFalse((new BotDetectionService())->isHuman($payload));
    }

    #[Test]
    public function securityLevelSetsTheNumberOfChecksThatMustPass(): void
    {
        $payload = self::payload(['scrollCount' => 0, 'keyCount' => 0, 'elapsed' => 1]);
        $subject = new BotDetectionService();

        self::assertTrue($subject->isHuman($payload, ['securityLevel' => 7]));
        self::assertFalse($subject->isHuman($payload, ['securityLevel' => 8]));
    }

    #[Test]
    public function submissionFasterThanMinFormTimeoutCountsAsFailedCheck(): void
    {
        $payload = self::payload(['elapsed' => 2]);
        $subject = new BotDetectionService();

        self::assertTrue($subject->isHuman($payload, ['minFormTimeout' => 2, 'securityLevel' => 10]));
        self::assertFalse($subject->isHuman($payload, ['minFormTimeout' => 3, 'securityLevel' => 10]));
    }

    #[Test]
    public function requireInteractionCanBeDisabled(): void
    {
        $payload = self::payload(['mouseMoveCount' => 0, 'touchCount' => 0]);
        $subject = new BotDetectionService();

        self::assertFalse($subject->isHuman($payload, ['requireInteraction' => true, 'securityLevel' => 10]));
        self::assertTrue($subject->isHuman($payload, ['requireInteraction' => false, 'securityLevel' => 10]));
    }

    #[Test]
    public function staleTimestampCountsAsFailedCheck(): void
    {
        $payload = self::payload(['ts' => (time() - 3600) * 1000]);

        self::assertFalse((new BotDetectionService())->isHuman($payload, ['securityLevel' => 10]));
    }

    #[Test]
    public function webdriverAloneRejects(): void
    {
        self::assertFalse((new BotDetectionService())->isHuman(self::payload(['webdriver' => true]), ['securityLevel' => 0]));
    }

    #[Test]
    public function headlessDefaultScreenSizeAloneRejects(): void
    {
        $payload = self::payload(['screenWidth' => 800, 'screenHeight' => 600]);

        self::assertFalse((new BotDetectionService())->isHuman($payload, ['securityLevel' => 0]));
    }

    #[Test]
    public function securityLevelAboveTheNumberOfChecksIsCapped(): void
    {
        self::assertTrue((new BotDetectionService())->isHuman(self::payload(), ['securityLevel' => 99]));
    }

    #[Test]
    public function nonNumericValuesCountAsZeroInsteadOfFailingWithAnError(): void
    {
        $payload = self::payload([
            'screenWidth' => 'wide',
            'screenHeight' => ['tall'],
            'elapsed' => 'long',
            'mouseMoveCount' => null,
            'ts' => 'now',
        ]);
        $subject = new BotDetectionService();

        self::assertFalse($subject->isHuman($payload, ['securityLevel' => 7]));
        self::assertTrue($subject->isHuman($payload, ['securityLevel' => 5]));
    }

    #[Test]
    public function numericStringsAreAccepted(): void
    {
        self::assertTrue((new BotDetectionService())->isHuman(self::payload(['elapsed' => '12', 'scrollCount' => '4']), ['securityLevel' => 10]));
    }
}
