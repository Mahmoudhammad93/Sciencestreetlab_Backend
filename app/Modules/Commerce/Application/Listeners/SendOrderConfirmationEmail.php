<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\EnrollmentQrCodeRenderer;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendOrderConfirmationEmail implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    private const CLAIM_TTL_SECONDS = 900;

    public function __construct(
        private readonly EnrollmentQrCodeRenderer $qrCodes,
    ) {}

    public function handle(OrderFulfilled $event): void
    {
        $orderId = $event->order->id;

        if (! $this->claim($orderId)) {
            return;
        }

        try {
            $order = Order::query()->with(['items.product', 'user', 'payment'])->find($orderId);

            if (! $order?->user?->email) {
                $this->releaseClaim($orderId);

                return;
            }

            Mail::to($order->user->email)->send(new OrderConfirmationMail(
                $order,
                $this->enrollmentQrs($order),
            ));
        } catch (Throwable $exception) {
            $this->releaseClaim($orderId);

            throw $exception;
        }

        $this->markSent($orderId);
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
