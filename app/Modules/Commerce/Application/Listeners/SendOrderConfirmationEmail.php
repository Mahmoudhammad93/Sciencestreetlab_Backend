<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendOrderConfirmationEmail implements ShouldQueue
{
    public function handle(OrderPaid $event): void
    {
        $orderId = $event->order->id;

        $shouldSend = DB::transaction(function () use ($orderId): bool {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order || $order->status !== OrderStatus::Paid->value) {
                return false;
            }

            if ($order->confirmation_email_sent_at !== null) {
                return false;
            }

            $order->forceFill(['confirmation_email_sent_at' => now()])->save();

            return true;
        });

        if (! $shouldSend) {
            return;
        }

        $order = Order::query()->with(['items', 'user', 'payment'])->find($orderId);

        if (! $order?->user?->email) {
            return;
        }

        Mail::to($order->user->email)->send(new OrderConfirmationMail($order));
    }
}
