<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestPurchaseClaim;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class GuestPurchaseClaimService
{
    public const CLAIM_TTL_HOURS = 72;

    public function __construct(
        private readonly EnrollUserService $enrollUserService,
    ) {}

    /**
     * Authoritative My Orders ownership rule (mirrors OrderController::index).
     */
    public function satisfiesMyOrdersOwnership(Order $order, User $user): bool
    {
        return $order->user_id !== null && (int) $order->user_id === (int) $user->id;
    }

    /**
     * Idempotent claim mint for guest order ownership / later course activation.
     * Paid (or accepted COD) guest orders are eligible BEFORE Bosta Delivered.
     *
     * @return array{claim: GuestPurchaseClaim, raw_token: string|null, created: bool, resent: bool}
     */
    public function ensureClaimForOrder(Order $order): array
    {
        if (! $order->is_guest) {
            throw new DomainException('Claims are only for guest orders.');
        }

        if (! $this->orderIsOwnershipEligible($order)) {
            throw new DomainException('Order is not eligible for account claim yet.');
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

    /**
     * @deprecated Prefer OrderDeliveredMailService::notifyIfNeeded via OrderFulfilled listener.
     */
    public function ensureAndNotify(Order $order): void
    {
        app(OrderDeliveredMailService::class)->notifyIfNeeded($order);
    }

    public function sendClaimEmail(Order $order, string $rawToken): void
    {
        $email = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');
        $locale = in_array($default, ['ar', 'en'], true) ? $default : 'ar';
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');

        $courseNames = [];
        $order->loadMissing('items.product');
        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if (! $courseId) {
                continue;
            }
            $course = Course::query()->find($courseId);
            if ($course) {
                $courseNames[] = $course->getTranslation('title', 'ar', false)
                    ?: $course->getTranslation('title', 'en', false)
                    ?: (string) $item->product_name;
            }
        }

        Mail::to($email)->send(new OrderDeliveredMail(
            order: $order,
            courseNames: array_values(array_unique($courseNames)),
            accountExists: false,
            activationUrl: $frontend.'/claim/'.$rawToken,
            mailLocale: $locale,
        ));
    }

    /**
     * If a user already exists for the guest billing email, link the order.
     * Enrollment runs only when the order is already fulfilled.
     *
     * @return User|null
     */
    public function attachMatchingUserIfPresent(Order $order): ?User
    {
        if (! $order->is_guest || $order->user_id !== null) {
            return $order->user_id !== null
                ? User::query()->find($order->user_id)
                : null;
        }

        if (! $this->orderIsOwnershipEligible($order)) {
            return null;
        }

        $email = GuestTokenHasher::normalizeEmail((string) ($order->billing_address['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user === null) {
            return null;
        }

        $this->attachOrderToUser($order, $user);

        return $user;
    }

    /**
     * Authoritative guest→account ownership attach.
     * Course enrollment is applied only when fulfilled_at is set.
     *
     * @return array{order: Order, enrollments: list<int>, attached: bool}
     */
    public function attachOrderToUser(Order $order, User $user): array
    {
        return DB::transaction(function () use ($order, $user): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items.product']);

            if (! $locked->is_guest) {
                throw new DomainException('Order is not a guest order.');
            }

            if (! $this->orderIsOwnershipEligible($locked)) {
                throw new DomainException('Order is not eligible for claim.');
            }

            $billingEmail = GuestTokenHasher::normalizeEmail((string) ($locked->billing_address['email'] ?? ''));
            $userEmail = GuestTokenHasher::normalizeEmail((string) $user->email);
            if ($billingEmail === '' || $userEmail === '' || ! hash_equals($billingEmail, $userEmail)) {
                throw new DomainException('Authenticated account email does not match this claim.');
            }

            if ($locked->user_id !== null && (int) $locked->user_id !== (int) $user->id) {
                throw new DomainException('Order is already linked to a different account.');
            }

            if ($locked->user_id === null) {
                $locked->update(['user_id' => $user->id]);
            }

            $enrollmentIds = [];
            if ($locked->fulfilled_at !== null) {
                $enrollmentIds = $this->enrollCourseItems($locked, $user);
            }

            $this->markOpenClaimsConsumed($locked, (int) $user->id);

            return [
                'order' => $locked->fresh(['items']) ?? $locked,
                'enrollments' => $enrollmentIds,
                'attached' => true,
            ];
        });
    }

    /**
     * Paid online, or accepted COD checkout, and not cancelled/refunded.
     */
    public function orderIsOwnershipEligible(Order $order): bool
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

        // COD accepted at checkout before cash collection.
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

    /**
     * @return list<int>
     */
    private function enrollCourseItems(Order $order, User $user): array
    {
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

        return $enrollmentIds;
    }

    private function markOpenClaimsConsumed(Order $order, int $userId): void
    {
        GuestPurchaseClaim::query()
            ->where('order_id', $order->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'consumed_at' => now(),
                'consumed_by_user_id' => $userId,
            ]);
    }

    /**
     * Issue a raw claim token for an email CTA.
     * Rehashes an active claim in place when confirmation already minted one
     * (raw tokens are never persisted).
     *
     * @return array{claim: GuestPurchaseClaim, raw_token: string}
     */
    public function issueRawTokenForEmail(Order $order): array
    {
        if (! $order->is_guest) {
            throw new DomainException('Claims are only for guest orders.');
        }

        if (! $this->orderIsOwnershipEligible($order)) {
            throw new DomainException('Order is not eligible for account claim yet.');
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

            $raw = GuestTokenHasher::generateRaw();

            if ($existing && $existing->expires_at?->isFuture()) {
                $existing->update([
                    'token_hash' => GuestTokenHasher::hash($raw),
                ]);

                return ['claim' => $existing->fresh() ?? $existing, 'raw_token' => $raw];
            }

            if ($existing) {
                $existing->update(['revoked_at' => now()]);
            }

            $claim = GuestPurchaseClaim::query()->create([
                'order_id' => $locked->id,
                'token_hash' => GuestTokenHasher::hash($raw),
                'email' => $email,
                'expires_at' => now()->addHours(self::CLAIM_TTL_HOURS),
            ]);

            return ['claim' => $claim, 'raw_token' => $raw];
        });
    }

    /**
     * Rotate the active claim so a new raw token can be included in email CTAs.
     * Needed because raw tokens are never stored after mint (confirmation may mint first).
     *
     * @return array{claim: GuestPurchaseClaim, raw_token: string}
     */
    public function rotateClaimToken(Order $order): array
    {
        return $this->issueRawTokenForEmail($order);
    }

    /**
     * @return array{claim: GuestPurchaseClaim, raw_token: string}
     */
    public function resend(Order $order): array
    {
        $result = $this->issueRawTokenForEmail($order);
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
     * Atomic guest claim activation.
     *
     * Critical path (one DB transaction):
     * lock claim → validate → create/resolve user → attach order → assert ownership
     * → recover entitlement if eligible (soft-fail) → consume claim → assert claim → commit.
     *
     * @param  array{name?: string, password?: string, phone?: string|null, locale?: string|null}|null  $registration
     * @return array{
     *   order: Order,
     *   enrollments: list<int>,
     *   user: User,
     *   created_user: bool,
     *   idempotent: bool,
     *   course_ready: bool,
     *   visible_in_my_orders: bool,
     * }
     */
    public function activate(string $rawToken, ?User $actingUser = null, ?array $registration = null): array
    {
        $hash = GuestTokenHasher::hash($rawToken);

        try {
            $result = DB::transaction(function () use ($hash, $actingUser, $registration): array {
                $claim = GuestPurchaseClaim::query()
                    ->where('token_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                if (! $claim) {
                    throw new DomainException('Invalid claim token.');
                }

                /** @var Order $order */
                $order = Order::query()->whereKey($claim->order_id)->lockForUpdate()->firstOrFail();
                $order->loadMissing(['items.product']);

                $this->logActivation('GUEST_CLAIM_ACTIVATION_STARTED', [
                    'claim_id' => $claim->id,
                    'order_id' => $order->id,
                    'user_id' => $actingUser?->id,
                ]);

                if ($claim->consumed_at !== null) {
                    return $this->idempotentConsumedActivation($claim, $order, $actingUser);
                }

                if ($claim->revoked_at !== null) {
                    throw new DomainException('Claim has been revoked.');
                }

                if ($claim->expires_at === null || $claim->expires_at->isPast()) {
                    throw new DomainException('Claim has expired.');
                }

                if (! $order->is_guest) {
                    throw new DomainException('Order is not a guest order.');
                }

                if (! $this->orderIsOwnershipEligible($order)) {
                    throw new DomainException('Order is not eligible for claim.');
                }

                [$user, $createdUser] = $this->resolveActivationUser($claim, $actingUser, $registration);

                $this->logActivation('GUEST_CLAIM_USER_RESOLVED', [
                    'claim_id' => $claim->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'created_user' => $createdUser,
                ]);

                if ($order->user_id !== null && (int) $order->user_id !== (int) $user->id) {
                    throw new DomainException('Order is already linked to a different account.');
                }

                if ($order->user_id === null) {
                    $order->update(['user_id' => $user->id]);
                }

                $persisted = Order::query()->whereKey($order->id)->firstOrFail();
                $this->assertOwnershipInvariant($claim, $persisted, $user);

                $this->logActivation('GUEST_CLAIM_ORDER_ATTACHED', [
                    'claim_id' => $claim->id,
                    'order_id' => $persisted->id,
                    'user_id' => $user->id,
                    'order_user_id' => $persisted->user_id,
                ]);

                $enrollmentIds = $this->recoverEntitlementSafely($persisted, $user);

                $claim->update([
                    'consumed_at' => now(),
                    'consumed_by_user_id' => $user->id,
                ]);
                $this->markOpenClaimsConsumed($persisted, (int) $user->id);

                $claimFresh = $claim->fresh() ?? $claim;
                if ($claimFresh->consumed_at === null || (int) $claimFresh->consumed_by_user_id !== (int) $user->id) {
                    $this->logActivation('GUEST_CLAIM_ACTIVATION_FAILED', [
                        'claim_id' => $claim->id,
                        'order_id' => $persisted->id,
                        'user_id' => $user->id,
                        'reason' => 'claim_consume_invariant',
                    ], 'error');

                    throw new RuntimeException('Guest claim consumption invariant violated.');
                }

                $this->logActivation('GUEST_CLAIM_CONSUMED', [
                    'claim_id' => $claimFresh->id,
                    'order_id' => $persisted->id,
                    'user_id' => $user->id,
                ]);

                return $this->activationPayload($persisted, $user, $enrollmentIds, $createdUser, false);
            });

            $this->logActivation('GUEST_CLAIM_ACTIVATION_COMPLETED', [
                'claim_id' => null,
                'order_id' => $result['order']->id,
                'user_id' => $result['user']->id,
                'order_user_id' => $result['order']->user_id,
                'activated_user_id' => $result['user']->id,
                'ownership_match' => true,
                'idempotent' => $result['idempotent'],
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logActivation('GUEST_CLAIM_ACTIVATION_FAILED', [
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ], $e instanceof DomainException ? 'warning' : 'error');

            throw $e;
        }
    }

    /**
     * Authenticated consume entry (delegates to activate).
     *
     * @return array{order: Order, enrollments: list<int>}
     */
    public function consume(string $rawToken, User $user): array
    {
        $result = $this->activate($rawToken, $user, null);

        return [
            'order' => $result['order'],
            'enrollments' => $result['enrollments'],
        ];
    }

    /**
     * @param  array{name?: string, password?: string, phone?: string|null, locale?: string|null}|null  $registration
     * @return array{0: User, 1: bool}
     */
    private function resolveActivationUser(GuestPurchaseClaim $claim, ?User $actingUser, ?array $registration): array
    {
        if ($actingUser !== null) {
            $userEmail = GuestTokenHasher::normalizeEmail((string) $actingUser->email);
            if ($userEmail === '' || ! hash_equals($claim->email, $userEmail)) {
                throw new DomainException('Authenticated account email does not match this claim.');
            }

            return [$actingUser, false];
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$claim->email])->first();
        if ($existing !== null) {
            throw new DomainException('Authentication required to claim this purchase.');
        }

        if ($registration === null) {
            throw new DomainException('Authentication required to claim this purchase.');
        }

        $name = trim((string) ($registration['name'] ?? ''));
        $password = (string) ($registration['password'] ?? '');
        if ($name === '' || $password === '') {
            throw new DomainException('Authentication required to claim this purchase.');
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $claim->email,
            'phone' => $registration['phone'] ?? null,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'locale' => $registration['locale'] ?? config('sciencestreet.default_locale'),
        ]);

        return [$user, true];
    }

    /**
     * @return array{
     *   order: Order,
     *   enrollments: list<int>,
     *   user: User,
     *   created_user: bool,
     *   idempotent: bool,
     *   course_ready: bool,
     *   visible_in_my_orders: bool,
     * }
     */
    private function idempotentConsumedActivation(
        GuestPurchaseClaim $claim,
        Order $order,
        ?User $actingUser,
    ): array {
        if ($actingUser === null) {
            throw new DomainException('Claim has already been used.');
        }

        $userEmail = GuestTokenHasher::normalizeEmail((string) $actingUser->email);
        if ($userEmail === '' || ! hash_equals($claim->email, $userEmail)) {
            throw new DomainException('Claim has already been used.');
        }

        // Historical heal: consumed claim but order never attached to matching user.
        if ($order->user_id === null) {
            $order->update(['user_id' => $actingUser->id]);
            $order = Order::query()->whereKey($order->id)->firstOrFail();
        }

        if ((int) $order->user_id !== (int) $actingUser->id) {
            throw new DomainException('Order is already linked to a different account.');
        }

        $this->assertOwnershipInvariant($claim, $order, $actingUser);
        $enrollmentIds = $this->recoverEntitlementSafely($order, $actingUser);

        $this->logActivation('GUEST_CLAIM_ACTIVATION_COMPLETED', [
            'claim_id' => $claim->id,
            'order_id' => $order->id,
            'user_id' => $actingUser->id,
            'order_user_id' => $order->user_id,
            'activated_user_id' => $actingUser->id,
            'ownership_match' => true,
            'idempotent' => true,
        ]);

        return $this->activationPayload($order, $actingUser, $enrollmentIds, false, true);
    }

    private function assertOwnershipInvariant(GuestPurchaseClaim $claim, Order $order, User $user): void
    {
        if ((int) $order->user_id !== (int) $user->id || ! $this->satisfiesMyOrdersOwnership($order, $user)) {
            $this->logActivation('GUEST_CLAIM_ACTIVATION_FAILED', [
                'claim_id' => $claim->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'order_user_id' => $order->user_id,
                'activated_user_id' => $user->id,
                'ownership_match' => false,
                'reason' => 'ownership_invariant',
            ], 'error');

            throw new RuntimeException('Guest claim ownership invariant violated.');
        }
    }

    /**
     * Enrollment must never roll back committed ownership intent.
     * Called inside the activation transaction; failures are logged and swallowed.
     *
     * @return list<int>
     */
    private function recoverEntitlementSafely(Order $order, User $user): array
    {
        if ($order->fulfilled_at === null) {
            return [];
        }

        try {
            return $this->enrollCourseItems($order, $user);
        } catch (Throwable $e) {
            Log::error('GUEST_CLAIM_ENROLLMENT_FAILED', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array{
     *   order: Order,
     *   enrollments: list<int>,
     *   user: User,
     *   created_user: bool,
     *   idempotent: bool,
     *   course_ready: bool,
     *   visible_in_my_orders: bool,
     * }
     */
    private function activationPayload(
        Order $order,
        User $user,
        array $enrollmentIds,
        bool $createdUser,
        bool $idempotent,
    ): array {
        $fresh = $order->fresh(['items']) ?? $order;

        return [
            'order' => $fresh,
            'enrollments' => $enrollmentIds,
            'user' => $user,
            'created_user' => $createdUser,
            'idempotent' => $idempotent,
            'course_ready' => $fresh->fulfilled_at !== null,
            'visible_in_my_orders' => $this->satisfiesMyOrdersOwnership($fresh, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logActivation(string $event, array $context, string $level = 'info'): void
    {
        $safe = array_intersect_key($context, array_flip([
            'claim_id',
            'order_id',
            'user_id',
            'order_user_id',
            'activated_user_id',
            'ownership_match',
            'created_user',
            'idempotent',
            'reason',
            'exception',
        ]));
        $safe['event'] = $event;

        match ($level) {
            'error' => Log::error($event, $safe),
            'warning' => Log::warning($event, $safe),
            default => Log::info($event, $safe),
        };
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
        if (! $order || ! $this->orderIsOwnershipEligible($order)) {
            return ['status' => 'ineligible'];
        }

        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$claim->email])->exists();
        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $suggestedName = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        $courseNames = [];
        $order->loadMissing('items.product');
        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? $item->product?->course_id;
            if (! $courseId) {
                continue;
            }
            $course = Course::query()->find($courseId);
            if ($course) {
                $title = $course->getTranslation('title', 'ar', false)
                    ?: $course->getTranslation('title', 'en', false);
                if (is_string($title) && $title !== '') {
                    $courseNames[] = $title;
                }
            }
        }

        return [
            'status' => 'valid',
            'email' => $claim->email,
            'suggested_name' => $suggestedName !== '' ? $suggestedName : null,
            'course_names' => array_values(array_unique($courseNames)),
            'expires_at' => $claim->expires_at?->toIso8601String(),
            'order_number' => $order->order_number,
            'requires_login' => $existingUser,
            'requires_registration' => ! $existingUser,
            'course_ready' => $order->fulfilled_at !== null,
            'requires_delivery_fulfillment' => (bool) $order->requires_delivery_fulfillment,
        ];
    }
}
