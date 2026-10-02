<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;

final class OrderPaymentMethod
{
    public const COD_GATEWAY = 'cod';

    public static function fromOrder(Order $order): PaymentMethod
    {
        $payment = self::latestPayment($order);
        if ($payment === null) {
            return PaymentMethod::Online;
        }

        if ($payment->gateway === self::COD_GATEWAY
            || in_array(strtolower((string) $payment->payment_method), [
                PaymentMethod::CashOnDelivery->value,
                'cod',
            ], true)
        ) {
            return PaymentMethod::CashOnDelivery;
        }

        return PaymentMethod::Online;
    }

    public static function isCashOnDelivery(Order $order): bool
    {
        return self::fromOrder($order)->isCashOnDelivery();
    }

    public static function latestPayment(Order $order): ?Payment
    {
        if ($order->relationLoaded('payment') && $order->payment instanceof Payment) {
            // Prefer newest payment when available.
        }

        return Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();
    }
}
