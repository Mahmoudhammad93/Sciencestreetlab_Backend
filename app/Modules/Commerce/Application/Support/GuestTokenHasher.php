<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

final class GuestTokenHasher
{
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function generateRaw(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(32, $bytes))), '+/', '-_'), '=');
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
