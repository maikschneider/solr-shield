<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Service;

/**
 * Validates bot-detection payloads collected by the client-side JS.
 *
 * Returns true when the request appears to originate from a human user,
 * false when heuristics indicate an automated/headless client.
 */
final class BotDetectionService
{
    public function isHuman(string $payload, array $settings = []): bool
    {
        $data = json_decode(base64_decode($payload, true), true);
        if (!is_array($data)) {
            return false;
        }

        $minTimeout = (int)($settings['minFormTimeout'] ?? 3);
        $securityLevel = (int)($settings['securityLevel'] ?? 7);
        $requireInteraction = (bool)($settings['requireInteraction'] ?? true);

        $checks = [];

        // Hard checks: always enforced
        $checks[] = empty($data['webdriver']);
        $checks[] = !$this->isHeadlessResolution($data['screenWidth'] ?? 0, $data['screenHeight'] ?? 0);

        // Soft checks: counted against securityLevel
        $checks[] = (int)($data['elapsed'] ?? 0) >= $minTimeout;
        $checks[] = (int)($data['mouseMoveCount'] ?? 0) > 0 || (int)($data['touchCount'] ?? 0) > 0 || !$requireInteraction;
        $checks[] = (int)($data['scrollCount'] ?? 0) > 0;
        $checks[] = (int)($data['keyCount'] ?? 0) > 0;
        $checks[] = isset($data['viewportWidth']) && (int)$data['viewportWidth'] > 0;
        $checks[] = isset($data['viewportHeight']) && (int)$data['viewportHeight'] > 0;
        $checks[] = isset($data['elapsed']) && (int)$data['elapsed'] < 3600;
        $checks[] = isset($data['ts']) && abs(time() - (int)($data['ts'] / 1000)) < 600;

        $passed = count(array_filter($checks));
        $total = count($checks);

        return $passed >= min($securityLevel, $total);
    }

    private function isHeadlessResolution(int $width, int $height): bool
    {
        return $width === 800 && $height === 600;
    }
}
