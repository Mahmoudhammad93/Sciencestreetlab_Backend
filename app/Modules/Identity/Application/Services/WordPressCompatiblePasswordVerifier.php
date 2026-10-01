<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

/**
 * WordPress-compatible password verification (read-only; never stores hashes).
 *
 * Modern WordPress 6.8+ hashes use the `$wp$2y$…` wrapper:
 *   password_verify(
 *     base64_encode(hash_hmac('sha384', $plaintext, 'wp-sha384', true)),
 *     substr($hash, 3)
 *   );
 *
 * Plaintext MUST NOT be verified directly against substr($hash, 3).
 */
final class WordPressCompatiblePasswordVerifier
{
    private const WP_HMAC_KEY = 'wp-sha384';

    public function verify(string $plaintext, string $storedHash): bool
    {
        if ($plaintext === '' || $storedHash === '') {
            return false;
        }

        // Reject absurdly long passwords (WordPress also caps at 4096).
        if (strlen($plaintext) > 4096) {
            return false;
        }

        try {
            if (str_starts_with($storedHash, '$wp')) {
                return $this->verifyModernWpHash($plaintext, $storedHash);
            }

            // Defensive: bare bcrypt (plugins / pre-wrapper).
            if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$2b$')) {
                return password_verify($plaintext, $storedHash);
            }

            // Defensive: ancient MD5 (32 hex).
            if (preg_match('/^[a-f0-9]{32}$/i', $storedHash) === 1) {
                return hash_equals(strtolower($storedHash), md5($plaintext));
            }
        } catch (\Throwable) {
            return false;
        }

        // $P$ / $H$ phpass intentionally unsupported here (none in current dump;
        // incorrect phpass would be worse than returning false).
        return false;
    }

    private function verifyModernWpHash(string $plaintext, string $storedHash): bool
    {
        // Expect `$wp$2y$…` shape: leading `$wp` then a standard bcrypt string.
        if (! str_starts_with($storedHash, '$wp$2y$') && ! str_starts_with($storedHash, '$wp$2a$') && ! str_starts_with($storedHash, '$wp$2b$')) {
            return false;
        }

        if (strlen($storedHash) < 10) {
            return false;
        }

        $bcrypt = substr($storedHash, 3);
        if ($bcrypt === '' || $bcrypt[0] !== '$') {
            return false;
        }

        $passwordToVerify = base64_encode(hash_hmac('sha384', $plaintext, self::WP_HMAC_KEY, true));

        return password_verify($passwordToVerify, $bcrypt);
    }

    /**
     * Build a modern `$wp$2y$` hash for deterministic tests only.
     */
    public static function makeModernTestHash(string $plaintext, int $cost = 4): string
    {
        $passwordToHash = base64_encode(hash_hmac('sha384', $plaintext, self::WP_HMAC_KEY, true));
        $bcrypt = password_hash($passwordToHash, PASSWORD_BCRYPT, ['cost' => $cost]);

        return '$wp'.$bcrypt;
    }
}
