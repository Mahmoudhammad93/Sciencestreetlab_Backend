<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use InvalidArgumentException;

final class PaymobHmacValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function isValid(array $payload): bool
    {
        $secret = config('paymob.hmac_secret');

        if (! filled($secret)) {
            return false;
        }

        $provided = $payload['hmac'] ?? null;

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $fields = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order',
            'owner',
            'pending',
            'source_data_pan',
            'source_data_sub_type',
            'source_data_type',
            'success',
        ];

        $concatenated = '';

        foreach ($fields as $field) {
            $value = data_get($payload, 'obj.'.$field, data_get($payload, $field));
            $concatenated .= $this->stringify($value);
        }

        $calculated = hash_hmac('sha512', $concatenated, $secret);

        return hash_equals($calculated, $provided);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
