<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Service;

/**
 * Validates bot-detection payloads collected by the client-side JS.
 *
 * Returns true when the request appears to originate from a human user,
 * false when heuristics indicate an automated/headless client.
 *
 * Ten checks: the two hard checks (no `navigator.webdriver`, no headless
 * default screen size) must pass, and at least `securityLevel` of all ten
 * must pass. Every value comes from the client, so anything that is not a
 * number counts as zero instead of failing the request with an error.
 */
final class BotDetectionService
{
    private const CHECK_COUNT = 10;

    /**
     * @param array<string, mixed> $settings
     */
    public function isHuman(string $payload, array $settings = []): bool
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return false;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return false;
        }

        if (!empty($data['webdriver']) || $this->isHeadlessResolution($this->int($data, 'screenWidth'), $this->int($data, 'screenHeight'))) {
            return false;
        }

        $minTimeout = $this->int($settings, 'minFormTimeout', 3);
        $securityLevel = max(0, min(self::CHECK_COUNT, $this->int($settings, 'securityLevel', 7)));
        $requireInteraction = (bool)($settings['requireInteraction'] ?? true);
        $elapsed = $this->int($data, 'elapsed', -1);

        $softChecks = [
            $elapsed >= $minTimeout,
            $this->int($data, 'mouseMoveCount') > 0 || $this->int($data, 'touchCount') > 0 || !$requireInteraction,
            $this->int($data, 'scrollCount') > 0,
            $this->int($data, 'keyCount') > 0,
            $this->int($data, 'viewportWidth') > 0,
            $this->int($data, 'viewportHeight') > 0,
            $elapsed >= 0 && $elapsed < 3600,
            abs(time() - intdiv($this->int($data, 'ts'), 1000)) < 600,
        ];

        return 2 + count(array_filter($softChecks)) >= $securityLevel;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (int)$value
            : $default;
    }

    private function isHeadlessResolution(int $width, int $height): bool
    {
        return $width === 800 && $height === 600;
    }
}
