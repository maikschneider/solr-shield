<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Service;

/**
 * Generates and validates HMAC-SHA256 tokens for URI obfuscation.
 *
 * Token size: 16 base64url characters (12 bytes / 96 bits).
 * Tokens are stateless and do not expire — they are valid as long as the
 * TYPO3 encryptionKey remains unchanged.
 */
final class TokenService
{
    public function generateToken(): string
    {
        return $this->hmac();
    }

    public function isValidToken(string $token): bool
    {
        return hash_equals($this->hmac(), $token);
    }

    private function hmac(): string
    {
        $raw = hash_hmac(
            'sha256',
            'solr-shield',
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '',
            true
        );
        // 12 bytes → 16 base64url characters (no padding: 12 % 3 === 0)
        return rtrim(strtr(base64_encode(substr($raw, 0, 12)), '+/', '-_'), '=');
    }
}
