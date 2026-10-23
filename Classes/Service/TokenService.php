<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Service;

/**
 * Encodes and verifies the signed `_ss` parameter.
 *
 * `_ss` is base64(JSON) with the `tx_solr` query string `p`, an optional list
 * `f` of form fields the visitor may fill in freely, and the signature `s`:
 * the first 12 bytes (16 base64url characters) of an HMAC-SHA256 over `p` and
 * `f`, keyed with the TYPO3 encryptionKey. Signatures do not expire, so links
 * stay valid until the encryptionKey changes, but they only cover the exact
 * parameters the server signed.
 */
final class TokenService
{
    /**
     * @param list<string> $freeFields field paths below `tx_solr`, e.g. `[q]` or `[filter][]`
     */
    public function encode(string $query, array $freeFields = []): string
    {
        $data = ['p' => $query];
        if ($freeFields !== []) {
            $data['f'] = $freeFields;
        }
        $data['s'] = $this->sign($query, $freeFields);

        return base64_encode((string)json_encode($data));
    }

    /**
     * @return array{params: array<array-key, mixed>, freeFields: list<string>}|null
     */
    public function decode(string $value): ?array
    {
        $json = base64_decode($value, true);
        if ($json === false || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['p'] ?? null) || !is_string($data['s'] ?? null)) {
            return null;
        }
        $freeFields = $data['f'] ?? [];
        if (!is_array($freeFields) || !array_is_list($freeFields) || array_filter($freeFields, is_string(...)) !== $freeFields) {
            return null;
        }
        if (!hash_equals($this->sign($data['p'], $freeFields), $data['s'])) {
            return null;
        }
        parse_str($data['p'], $params);

        return ['params' => $params, 'freeFields' => $freeFields];
    }

    /**
     * @param list<string> $freeFields
     */
    private function sign(string $query, array $freeFields): string
    {
        $raw = hash_hmac(
            'sha256',
            "solr-shield\n" . $query . "\n" . implode("\n", $freeFields),
            (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''),
            true
        );
        // 12 bytes → 16 base64url characters (no padding: 12 % 3 === 0)
        return rtrim(strtr(base64_encode(substr($raw, 0, 12)), '+/', '-_'), '=');
    }
}
