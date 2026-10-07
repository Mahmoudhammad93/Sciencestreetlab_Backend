<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\GuestOrderCapabilityService;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * EMAIL 1 — order receipt ("تم استلام طلبك").
 *
 * Triggered on OrderPaid (online) and invoked directly after COD checkout acceptance.
 * Never waits for Bosta Delivered / OrderFulfilled.
 */
final class SendOrderConfirmationEmail implements ShouldQueue
{
    public int $tries = 3;

    /** Mail must not run until OrderPaid transaction commits. */
    public bool $afterCommit = true;

    private const CLAIM_TTL_SECONDS = 900;

    public function __construct(
        private readonly GuestOrderCapabilityService $guestCapabilities,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->sendForOrderId($event->order->id);
    }

    /**
     * COD checkout acceptance path (no OrderPaid until delivery cash collection).
     *
     * @return string|null Raw guest status token embedded in the email (when minted),
     *                     so checkout can return the SAME token the browser redirects with.
     */
    public function sendForAcceptedOrder(Order $order): ?string
    {
        return $this->sendForOrderId($order->id);
    }

    private function sendForOrderId(int $orderId): ?string
    {
        if (! $this->claim($orderId)) {
            return null;
        }

        $rawStatusToken = null;

        try {
            $order = Order::query()->with(['items.product', 'user', 'payment', 'bostaShipment'])->find($orderId);

            $recipient = $this->recipientEmail($order);

            if (! $order || $recipient === null) {
                $this->releaseClaim($orderId);

                return null;
            }

            // Guest-safe "View order" only. Account activation is EMAIL 2 on Delivered.
            if ($order->is_guest && $order->user_id === null) {
                $rawStatusToken = $this->guestCapabilities->rotateStatusTokenForDelivery($order);
            }

            $mailLocale = $this->resolveMailLocale($order);

            Mail::to($recipient)->send(new OrderConfirmationMail(
                $order,
                [],
                $rawStatusToken,
                $mailLocale,
                null,
            ));
        } catch (Throwable $exception) {
            $this->releaseClaim($orderId);

            throw $exception;
        }

        $this->markSent($orderId);

        return $rawStatusToken;
    }

    private function recipientEmail(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        // Prefer immutable billing snapshot so admin-placed / mismatched user_id
        // orders still email the customer, never an unrelated account by default.
        $billing = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));
        if ($billing !== '' && filter_var($billing, FILTER_VALIDATE_EMAIL)) {
            return $billing;
        }

        $userEmail = GuestTokenHasher::normalizeEmail((string) ($order->user?->email ?? ''));
        if ($userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return $userEmail;
        }

        return null;
    }

    private function resolveMailLocale(Order $order): string
    {
        $userLocale = $order->user?->locale;
        if (is_string($userLocale) && in_array($userLocale, ['ar', 'en'], true)) {
            return $userLocale;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');

        return in_array($default, ['ar', 'en'], true) ? $default : 'ar';
    }

    private function claim(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = Order::query()->with('payment')->whereKey($orderId)->lockForUpdate()->first();

            if (! $order || $order->confirmation_email_sent_at !== null) {
                return false;
            }

            if (! $this->isAcceptedForReceipt($order)) {
                return false;
            }

            $claimedAt = $order->confirmation_email_claimed_at;
            if ($claimedAt !== null && $claimedAt->gt(now()->subSeconds(self::CLAIM_TTL_SECONDS))) {
                return false;
            }

            $order->forceFill(['confirmation_email_claimed_at' => now()])->save();

            return true;
        });
    }

    private function isAcceptedForReceipt(Order $order): bool
    {
        if (in_array($order->status, [
            OrderStatus::Cancelled->value,
            OrderStatus::Refunded->value,
        ], true) || $order->cancelled_at !== null) {
            return false;
        }

        if ($order->paid_at !== null) {
            return true;
        }

        // COD is accepted at checkout (processing) before cash collection / paid_at.
        if (OrderPaymentMethod::isCashOnDelivery($order)
            && in_array($order->status, [
                OrderStatus::Processing->value,
                OrderStatus::Paid->value,
                OrderStatus::Shipped->value,
                OrderStatus::Delivered->value,
            ], true)
        ) {
            return true;
        }

        return false;
    }

    private function releaseClaim(int $orderId): void
    {
        Order::query()
            ->whereKey($orderId)
            ->whereNull('confirmation_email_sent_at')
            ->update(['confirmation_email_claimed_at' => null]);
    }

    private function markSent(int $orderId): void
    {
        Order::query()->whereKey($orderId)->update([
            'confirmation_email_sent_at' => now(),
            'confirmation_email_claimed_at' => null,
        ]);
    }
}
