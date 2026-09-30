<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\EnrollmentQrCodeRenderer;
use App\Modules\Commerce\Application\Services\GuestOrderCapabilityService;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendOrderConfirmationEmail implements ShouldQueue
{
    public int $tries = 3;

    private const CLAIM_TTL_SECONDS = 900;

    public function __construct(
        private readonly EnrollmentQrCodeRenderer $qrCodes,
        private readonly GuestOrderCapabilityService $guestCapabilities,
    ) {}

    public function handle(OrderFulfilled $event): void
    {
        $orderId = $event->order->id;

        if (! $this->claim($orderId)) {
            return;
        }

        try {
            $order = Order::query()->with(['items.product', 'user', 'payment'])->find($orderId);

            // Guest recipient is always the immutable billing snapshot email.
            $recipient = $order?->user?->email
                ?: (is_array($order?->billing_address) ? ($order->billing_address['email'] ?? null) : null);

            if (! $order || ! is_string($recipient) || $recipient === '') {
                $this->releaseClaim($orderId);

                return;
            }

            $rawStatusToken = null;
            if ($order->is_guest && $order->user_id === null) {
                // Mint at send-time: raw status tokens cannot be recovered from hashes.
                // Rotates any prior active status capability so retries stay single-active.
                $rawStatusToken = $this->guestCapabilities->rotateStatusTokenForDelivery($order);
            }

            $mailLocale = $this->resolveMailLocale($order);

            Mail::to($recipient)->send(new OrderConfirmationMail(
                $order,
                $this->enrollmentQrs($order),
                $rawStatusToken,
                $mailLocale,
            ));
        } catch (Throwable $exception) {
            $this->releaseClaim($orderId);

            throw $exception;
        }

        $this->markSent($orderId);
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
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order || $order->fulfilled_at === null) {
                return false;
            }

            if ($order->confirmation_email_sent_at !== null) {
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

    /**
     * One QR per enrollment created for a course item on this order.
     *
     * @return list<array{course_name: string, verification_url: string, qr_png: string, filename: string}>
     */
    private function enrollmentQrs(Order $order): array
    {
        $qrs = [];
        $seen = [];

        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if (! $courseId) {
                continue;
            }

            $enrollment = Enrollment::query()
                ->with(['course', 'user'])
                ->where('user_id', $order->user_id)
                ->where('course_id', $courseId)
                ->first();

            if (! $enrollment || isset($seen[$enrollment->id])) {
                continue;
            }

            $seen[$enrollment->id] = true;
            $course = $enrollment->course;
            $courseName = 'Course';
            if ($course) {
                foreach ([app()->getLocale(), 'en', 'ar'] as $locale) {
                    $name = $course->getTranslation('title', $locale, false);
                    if (is_string($name) && $name !== '') {
                        $courseName = $name;
                        break;
                    }
                }
            }

            $url = $enrollment->verificationUrl();
            $qrs[] = [
                'course_name' => $courseName,
                'verification_url' => $url,
                'qr_png' => $this->qrCodes->png($url),
                'filename' => 'enrollment-verification-'.count($qrs).'.png',
            ];
        }

        return $qrs;
    }
}
