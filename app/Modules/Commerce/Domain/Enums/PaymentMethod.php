<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum PaymentMethod: string
{
    case Online = 'online';
    case CashOnDelivery = 'cash_on_delivery';

    public static function fromCheckoutInput(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'cod', 'cash_on_delivery', 'cash-on-delivery' => self::CashOnDelivery,
            'online', 'card', 'fawaterak', '' => self::Online,
            default => throw new \InvalidArgumentException('Unsupported payment method.'),
        };
    }

    public function isCashOnDelivery(): bool
    {
        return $this === self::CashOnDelivery;
    }
}
