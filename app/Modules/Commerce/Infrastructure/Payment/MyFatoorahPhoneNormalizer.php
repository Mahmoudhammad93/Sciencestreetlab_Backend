<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

final class MyFatoorahPhoneNormalizer
{
    /**
     * MyFatoorah accepts CustomerMobile as digits only, max length 11.
     */
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '01000000000';
        }

        // +20XXXXXXXXXX → 0XXXXXXXXXX (Egypt)
        if (str_starts_with($digits, '20') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 2);
        }

        // 1XXXXXXXXX without leading zero
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return substr($digits, 0, 11);
    }
}
