<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestOrderCapability;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

final class GuestOrderCapabilityService
{
    public const PAY_TTL_HOURS = 24;

    public const STATUS_TTL_DAYS = 30;

    /**
     * @return array{pay_token: string, status_token: string}
     */
    public function issueForGuestOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $this->revokeActive($order, GuestOrderCapability::TYPE_PAY);
            $this->revokeActive($order, GuestOrderCapability::TYPE_STATUS);

            $payRaw = GuestTokenHasher::generateRaw();
            $statusRaw = GuestTokenHasher::generateRaw();

            GuestOrderCapability::query()->create([
                'order_id' => $order->id,
                'type' => GuestOrderCapability::TYPE_PAY,
                'token_hash' => GuestTokenHasher::hash($payRaw),
                'expires_at' => now()->addHours(self::PAY_TTL_HOURS),
            ]);

            GuestOrderCapability::query()->create([
                'order_id' => $order->id,
                'type' => GuestOrderCapability::TYPE_STATUS,
                'token_hash' => GuestTokenHasher::hash($statusRaw),
                'expires_at' => now()->addDays(self::STATUS_TTL_DAYS),
            ]);

            return [
                'pay_token' => $payRaw,
                'status_token' => $statusRaw,
            ];
        });
    }

    public function assertPayAllowed(Order $order, ?string $rawToken): void
    {
        if ($rawToken === null || $rawToken === '') {
            throw new DomainException('Guest payment capability is required.');
        }

        $capability = GuestOrderCapability::query()
            ->where('order_id', $order->id)
            ->where('type', GuestOrderCapability::TYPE_PAY)
            ->where('token_hash', GuestTokenHasher::hash($rawToken))
            ->first();

        if (! $capability || ! $capability->isActive()) {
            throw new DomainException('Invalid or expired guest payment capability.');
        }
    }

    /**
     * Mint a fresh status capability for legitimate delivery (e.g. confirmation email).
     *
     * Raw tokens cannot be recovered from hashes, so delivery must mint at send-time.
     *
     * IMPORTANT: do NOT revoke prior active status tokens here. Checkout returns a
     * status token for the immediate /order-status redirect; the confirmation email
     * needs its own raw token. Revoking the checkout token caused guests to see
     * "order not found / unauthorized" right after a successful checkout.
     *
     * Refund/cancel still calls revokeAllForOrder(). Email idempotency is handled
     * by confirmation_email_sent_at, not by single-active status rows.
     *
     * @return string raw status token (caller must deliver once; never log/persist raw)
     */
    public function rotateStatusTokenForDelivery(Order $order): string
    {
        return DB::transaction(function () use ($order): string {
            $statusRaw = GuestTokenHasher::generateRaw();

            GuestOrderCapability::query()->create([
                'order_id' => $order->id,
                'type' => GuestOrderCapability::TYPE_STATUS,
                'token_hash' => GuestTokenHasher::hash($statusRaw),
                'expires_at' => now()->addDays(self::STATUS_TTL_DAYS),
            ]);

            return $statusRaw;
        });
    }

    public function findOrderByStatusToken(string $orderNumber, string $rawToken): ?Order
    {
        $capability = GuestOrderCapability::query()
            ->where('type', GuestOrderCapability::TYPE_STATUS)
            ->where('token_hash', GuestTokenHasher::hash($rawToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $capability) {
            return null;
        }

        $order = Order::query()->with(['items', 'payment', 'bostaShipment'])->find($capability->order_id);

        if (! $order || $order->order_number !== $orderNumber || ! $order->is_guest) {
            return null;
        }

        return $order;
    }

    public function revokeAllForOrder(Order $order): void
    {
        GuestOrderCapability::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function revokeActive(Order $order, string $type): void
    {
        GuestOrderCapability::query()
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
