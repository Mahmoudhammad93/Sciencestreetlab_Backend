<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;

/**
 * Authoritative customer-facing payment eligibility for an order.
 * Does not expose gateway secrets or raw provider payloads.
 */
final class OrderPaymentEligibility
{
    /**
     * @return array{
     *     is_paid: bool,
     *     payment_required: bool,
     *     payment_retry_allowed: bool,
     *     latest_payment_status: ?string,
     *     latest_payment_id: ?int
     * }
     */
    public function forOrder(Order $order): array
    {
        $latest = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $isPaid = $order->paid_at !== null
            || $order->status === OrderStatus::Paid->value
            || ($latest !== null && $latest->status === PaymentStatus::Completed->value);

        $terminalNonPayable = in_array($order->status, [
            OrderStatus::Cancelled->value,
            OrderStatus::Refunded->value,
        ], true) || $order->cancelled_at !== null;

        $hasSuccessfulPayment = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Completed->value)
            ->exists();

        $paymentRequired = ! $isPaid && ! $terminalNonPayable;
        $paymentRetryAllowed = $paymentRequired && ! $hasSuccessfulPayment;

        return [
            'is_paid' => $isPaid,
            'payment_required' => $paymentRequired,
            'payment_retry_allowed' => $paymentRetryAllowed,
            'latest_payment_status' => $latest?->status,
            'latest_payment_id' => $latest?->id,
        ];
    }

    public function assertRetryAllowed(Order $order): void
    {
        $eligibility = $this->forOrder($order);
        if (! $eligibility['payment_retry_allowed']) {
            throw new \DomainException('Online payment retry is not allowed for this order.');
        }
    }
}
