<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestPurchaseClaim;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\GuestCourseClaimMail;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class GuestPurchaseClaimService
{
    public const CLAIM_TTL_HOURS = 72;

    public function __construct(
        private readonly EnrollUserService $enrollUserService,
    ) {}

    /**
     * Idempotent: returns existing active claim raw token only when newly created.
     * When an active unconsumed claim already exists, regenerates token only if expired.
     *
     * @return array{claim: GuestPurchaseClaim, raw_token: string|null, created: bool, resent: bool}
     */
    public function ensureClaimForOrder(Order $order): array
    {
        if (! $order->is_guest) {
            throw new DomainException('Claims are only for guest orders.');
        }

        if ($order->fulfilled_at === null || $order->paid_at === null) {
            throw new DomainException('Order is not eligible for course claim yet.');
        }

        if (! $this->orderHasCourseEntitlement($order)) {
            throw new DomainException('Order has no course entitlements.');
        }

        $email = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Guest billing email is missing.');
        }

        return DB::transaction(function () use ($order, $email): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = GuestPurchaseClaim::query()
                ->where('order_id', $locked->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->expires_at?->isFuture()) {
                return ['claim' => $existing, 'raw_token' => null, 'created' => false, 'resent' => false];
            }

            if ($existing) {
                $existing->update(['revoked_at' => now()]);
            }

            $raw = GuestTokenHasher::generateRaw();
            $claim = GuestPurchaseClaim::query()->create([
                'order_id' => $locked->id,
                'token_hash' => GuestTokenHasher::hash($raw),
                'email' => $email,
                'expires_at' => now()->addHours(self::CLAIM_TTL_HOURS),
            ]);

            return [
                'claim' => $claim,
                'raw_token' => $raw,
                'created' => $existing === null,
                'resent' => $existing !== null,
            ];
        });
    }

    public function sendClaimEmail(Order $order, string $rawToken): void
    {
        $email = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');
        $locale = in_array($default, ['ar', 'en'], true) ? $default : 'ar';

        Mail::to($email)->send(new GuestCourseClaimMail($order, $rawToken, $locale));
    }

    /**
     * Create claim (if needed) and email when a new raw token is minted.
     */
    public function ensureAndNotify(Order $order): void
    {
        if (! $order->is_guest || ! $this->orderHasCourseEntitlement($order)) {
            return;
        }

        if ($order->fulfilled_at === null || $order->paid_at === null) {
            return;
        }

        $result = $this->ensureClaimForOrder($order);
        if ($result['raw_token'] !== null) {
            $this->sendClaimEmail($order, $result['raw_token']);
        }
    }

    /**
     * Force regenerate + resend for expired/active policy.
     *
     * @return array{claim: GuestPurchaseClaim, raw_token: string}
     */
    public function resend(Order $order): array
    {
        if (! $order->is_guest) {
            throw new DomainException('Claims are only for guest orders.');
        }

        $email = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));

        $result = DB::transaction(function () use ($order, $email): array {
            GuestPurchaseClaim::query()
                ->where('order_id', $order->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $raw = GuestTokenHasher::generateRaw();
            $claim = GuestPurchaseClaim::query()->create([
                'order_id' => $order->id,
                'token_hash' => GuestTokenHasher::hash($raw),
                'email' => $email,
                'expires_at' => now()->addHours(self::CLAIM_TTL_HOURS),
            ]);

            return ['claim' => $claim, 'raw_token' => $raw];
        });

        $this->sendClaimEmail($order, $result['raw_token']);

        return $result;
    }

    public function findByRawToken(string $rawToken): ?GuestPurchaseClaim
    {
        return GuestPurchaseClaim::query()
            ->with(['order.items.product'])
            ->where('token_hash', GuestTokenHasher::hash($rawToken))
            ->first();
    }

    /**
     * @return array{order: Order, enrollments: list<int>}
     */
    public function consume(string $rawToken, User $user): array
    {
        return DB::transaction(function () use ($rawToken, $user): array {
            $claim = GuestPurchaseClaim::query()
                ->where('token_hash', GuestTokenHasher::hash($rawToken))
                ->lockForUpdate()
                ->first();

            if (! $claim) {
                throw new DomainException('Invalid claim token.');
            }

            if ($claim->consumed_at !== null) {
                throw new DomainException('Claim has already been used.');
            }

            if ($claim->revoked_at !== null) {
                throw new DomainException('Claim has been revoked.');
            }

            if ($claim->expires_at === null || $claim->expires_at->isPast()) {
                throw new DomainException('Claim has expired.');
            }

            /** @var Order $order */
            $order = Order::query()->whereKey($claim->order_id)->lockForUpdate()->firstOrFail();
            $order->loadMissing(['items.product']);

            if ($order->paid_at === null || $order->fulfilled_at === null) {
                throw new DomainException('Order is not eligible for claim.');
            }

            if (in_array($order->status, ['cancelled', 'refunded'], true) || $order->cancelled_at !== null) {
                throw new DomainException('Order is no longer eligible.');
            }

            $userEmail = GuestTokenHasher::normalizeEmail((string) $user->email);
            if ($userEmail === '' || ! hash_equals($claim->email, $userEmail)) {
                throw new DomainException('Authenticated account email does not match this claim.');
            }

            if (! $order->is_guest) {
                throw new DomainException('Order is not a guest order.');
            }

            $order->update(['user_id' => $user->id]);

            $enrollmentIds = [];
            foreach ($order->items as $item) {
                $product = $item->product;
                $courseId = $item->metadata['course_id'] ?? $product?->course_id;
                $planId = $item->metadata['course_plan_id'] ?? $product?->course_plan_id;

                if (! $courseId) {
                    continue;
                }

                $course = Course::query()->find($courseId);
                if (! $course) {
                    continue;
                }

                $plan = null;
                if ($planId) {
                    $plan = CoursePlan::query()
                        ->whereKey($planId)
                        ->where('course_id', $course->id)
                        ->where('is_active', true)
                        ->first();
                }

                $enrollment = $this->enrollUserService->enroll($user, $course, $item->id, $plan);
                $enrollmentIds[] = $enrollment->id;
            }

            $claim->update([
                'consumed_at' => now(),
                'consumed_by_user_id' => $user->id,
            ]);

            return [
                'order' => $order->fresh(['items']) ?? $order,
                'enrollments' => $enrollmentIds,
            ];
        });
    }

    public function revokeForOrder(Order $order): void
    {
        GuestPurchaseClaim::query()
            ->where('order_id', $order->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function orderHasCourseEntitlement(Order $order): bool
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if ($courseId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safe public inspection payload.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $rawToken): array
    {
        $claim = $this->findByRawToken($rawToken);

        if (! $claim) {
            return ['status' => 'invalid'];
        }

        if ($claim->consumed_at !== null) {
            return ['status' => 'consumed'];
        }

        if ($claim->revoked_at !== null) {
            return ['status' => 'revoked'];
        }

        if ($claim->expires_at === null || $claim->expires_at->isPast()) {
            return ['status' => 'expired', 'email' => $claim->email];
        }

        $order = $claim->order;
        if (! $order || $order->paid_at === null || $order->fulfilled_at === null) {
            return ['status' => 'ineligible'];
        }

        $existingUser = User::query()->where('email', $claim->email)->exists();

        return [
            'status' => 'valid',
            'email' => $claim->email,
            'expires_at' => $claim->expires_at?->toIso8601String(),
            'order_number' => $order->order_number,
            'requires_login' => $existingUser,
            'requires_registration' => ! $existingUser,
        ];
    }
}
