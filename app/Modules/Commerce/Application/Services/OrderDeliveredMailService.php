<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Idempotent EMAIL 2: delivered + course ready / account activation.
 * Uses shipment.metadata marker when a Bosta shipment exists; otherwise a durable cache key.
 * No DDL required.
 */
final class OrderDeliveredMailService
{
    public function __construct(
        private readonly GuestPurchaseClaimService $claims,
        private readonly EnrollmentQrCodeRenderer $qrCodes,
    ) {}

    public function notifyIfNeeded(Order $order): void
    {
        $order = $order->fresh(['items.product', 'user', 'bostaShipment']) ?? $order;

        if ($order->fulfilled_at === null) {
            return;
        }

        if (! $this->orderHasCourseEntitlement($order)) {
            return;
        }

        if ($this->alreadySent($order)) {
            return;
        }

        $email = $this->recipientEmail($order);
        if ($email === null) {
            return;
        }

        $locale = $this->mailLocale($order);
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');
        $courseNames = $this->courseNames($order);

        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($order->is_guest && $order->user_id === null && $existingUser === null) {
            // Confirmation may already have minted a claim (raw token not retained).
            // Reissue in place so EMAIL2 always carries a usable secure /claim/{token} CTA.
            $issued = $this->claims->issueRawTokenForEmail($order);

            Mail::to($email)->send(new OrderDeliveredMail(
                order: $order,
                courseNames: $courseNames,
                accountExists: false,
                activationUrl: $frontend.'/claim/'.$issued['raw_token'],
                mailLocale: $locale,
            ));
            $this->markSent($order);

            return;
        }

        if ($order->is_guest && $order->user_id === null && $existingUser !== null) {
            $this->claims->attachOrderToUser($order, $existingUser);
            $order = $order->fresh(['items.product', 'user', 'bostaShipment']) ?? $order;
        }

        Mail::to($email)->send(new OrderDeliveredMail(
            order: $order,
            courseNames: $courseNames,
            accountExists: true,
            loginUrl: $frontend.'/my-account',
            forgotPasswordUrl: $frontend.'/my-account/lost-password',
            mailLocale: $locale,
            enrollmentQrs: $this->enrollmentQrs($order),
        ));
        $this->markSent($order);
    }

    /**
     * @return list<array{course_name: string, verification_url: string, qr_png: string, filename: string}>
     */
    private function enrollmentQrs(Order $order): array
    {
        if ($order->user_id === null) {
            return [];
        }

        $qrs = [];
        $seen = [];
        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if (! $courseId) {
                continue;
            }

            $enrollment = Enrollment::query()
                ->with('course')
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
                foreach (['ar', 'en', app()->getLocale()] as $locale) {
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

    private function recipientEmail(Order $order): ?string
    {
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

    /**
     * @return list<string>
     */
    private function courseNames(Order $order): array
    {
        $names = [];
        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if (! $courseId) {
                continue;
            }
            $course = Course::query()->find($courseId);
            if (! $course) {
                continue;
            }
            $title = $course->getTranslation('title', 'ar', false)
                ?: $course->getTranslation('title', 'en', false)
                ?: (string) $item->product_name;
            if ($title !== '' && ! in_array($title, $names, true)) {
                $names[] = $title;
            }
        }

        return $names;
    }

    private function orderHasCourseEntitlement(Order $order): bool
    {
        return $this->claims->orderHasCourseEntitlement($order);
    }

    private function alreadySent(Order $order): bool
    {
        $shipment = $order->bostaShipment;
        if ($shipment instanceof Shipment) {
            $meta = is_array($shipment->metadata) ? $shipment->metadata : [];

            return ! empty($meta['course_ready_email_sent_at']);
        }

        return Cache::has($this->cacheKey($order));
    }

    private function markSent(Order $order): void
    {
        $shipment = $order->bostaShipment;
        if ($shipment instanceof Shipment) {
            $meta = is_array($shipment->metadata) ? $shipment->metadata : [];
            $meta['course_ready_email_sent_at'] = now()->toIso8601String();
            $shipment->update(['metadata' => $meta]);

            return;
        }

        Cache::forever($this->cacheKey($order), now()->toIso8601String());
    }

    private function cacheKey(Order $order): string
    {
        return 'order_delivered_email_sent:'.($order->uuid ?: (string) $order->id);
    }

    private function mailLocale(Order $order): string
    {
        $userLocale = $order->user?->locale;
        if (is_string($userLocale) && in_array($userLocale, ['ar', 'en'], true)) {
            return $userLocale;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');

        return in_array($default, ['ar', 'en'], true) ? $default : 'ar';
    }
}
