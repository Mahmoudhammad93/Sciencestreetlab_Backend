<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use Illuminate\Validation\ValidationException;

/**
 * Delivery street/building address rules for physical checkout / Bosta drop-off.
 * City / district / zone are separate structured fields and are not covered here.
 */
final class DeliveryAddressValidator
{
    public const MIN_LENGTH = 15;

    public const MESSAGE = 'عنوان التوصيل يجب ألا يقل عن 15 حرفًا.';

    /**
     * Trim the street address field only (preserve internal spaces).
     *
     * @param  array<string, mixed>|null  $address
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        if (array_key_exists('address', $address)) {
            $address['address'] = trim((string) $address['address']);
        }

        return $address;
    }

    public static function isValid(string $address): bool
    {
        return mb_strlen(trim($address)) >= self::MIN_LENGTH;
    }

    /**
     * Effective drop-off line after the same merge rules as CheckoutService.
     *
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     */
    public static function effectiveAddress(array $billing, ?array $shipping): string
    {
        $merged = array_merge([
            'address' => $billing['address'] ?? '',
        ], $shipping ?? []);

        return trim((string) ($merged['address'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     */
    public static function errorField(array $billing, ?array $shipping): string
    {
        if (is_array($shipping) && array_key_exists('address', $shipping)) {
            return 'shipping_address.address';
        }

        return 'billing_address.address';
    }

    /**
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     *
     * @throws ValidationException
     */
    public static function assertForPhysicalCheckout(array $billing, ?array $shipping): void
    {
        $address = self::effectiveAddress($billing, $shipping);

        if (! self::isValid($address)) {
            throw ValidationException::withMessages([
                self::errorField($billing, $shipping) => [self::MESSAGE],
            ]);
        }
    }
}
