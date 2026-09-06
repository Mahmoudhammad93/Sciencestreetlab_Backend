<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EnrollUserService
{
    public function __construct(
        private readonly CoursePlanEntitlementSyncService $entitlementSync,
    ) {}

    public function enroll(User $user, Course $course, ?int $orderItemId = null, ?CoursePlan $plan = null): Enrollment
    {
        if ($plan !== null && $plan->course_id !== $course->id) {
            throw new DomainException('Course plan does not belong to this course.');
        }

        if ($plan !== null && ! $plan->is_active) {
            throw new DomainException('Course plan is not active.');
        }

        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $course, $orderItemId, $plan): Enrollment {
            $startedAt = now();
            $expiresAt = $plan?->calculateExpiresAt($startedAt);

            $enrollment = Enrollment::query()->create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'course_plan_id' => $plan?->id,
                'order_item_id' => $orderItemId,
                'status' => EnrollmentStatus::Active,
                'progress_percent' => 0,
                'enrolled_at' => $startedAt,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'grant_certificate' => $plan?->grant_certificate ?? false,
            ]);

            if ($plan !== null) {
                $this->entitlementSync->snapshotEntitlementsForEnrollment($enrollment, $plan);
            }

            return $enrollment->fresh(['coursePlan', 'entitlements']);
        });
    }

    /**
     * @return array{status: string, enrollment?: Enrollment, order?: Order, created?: bool}
     */
    public function enrollDirect(User $user, Course $course): array
    {
        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->first();

        if ($existing) {
            return ['status' => 'already_enrolled', 'enrollment' => $existing, 'created' => false];
        }

        return match ($course->access_type) {
            AccessType::Free => [
                'status' => 'active',
                'enrollment' => $this->enroll($user, $course),
                'created' => true,
            ],
            AccessType::Paid => [
                'status' => 'awaiting_payment',
                'order' => $this->createOrderForCourseProduct($user, $course),
            ],
            AccessType::School => throw new DomainException('School courses require school membership.', 403),
            AccessType::Closed => throw new DomainException('This course is closed for enrollment.', 403),
        };
    }

    /**
     * @return array{status: string, enrollment?: Enrollment, order?: Order}
     */
    public function enrollWithPlan(User $user, CoursePlan $plan): array
    {
        $plan->loadMissing('course', 'product');

        if ($plan->isFree()) {
            return [
                'status' => 'active',
                'enrollment' => $this->enroll($user, $plan->course, null, $plan),
            ];
        }

        return [
            'status' => 'awaiting_payment',
            'order' => $this->createOrderForPlanProduct($user, $plan),
        ];
    }

    private function createOrderForCourseProduct(User $user, Course $course): Order
    {
        $product = Product::query()->findOrFail($course->product_id);

        return $this->createAwaitingPaymentOrder($user, $product, $course->getTranslation('title', app()->getLocale()));
    }

    private function createOrderForPlanProduct(User $user, CoursePlan $plan): Order
    {
        $product = $plan->product ?? Product::query()->findOrFail($plan->product_id);
        $label = $plan->course->getTranslation('title', app()->getLocale()).' - '.$plan->getTranslation('name', app()->getLocale());

        return $this->createAwaitingPaymentOrder($user, $product, $label);
    }

    private function createAwaitingPaymentOrder(User $user, Product $product, string $label): Order
    {
        return DB::transaction(function () use ($user, $product, $label): Order {
            $order = Order::create([
                'user_id' => $user->id,
                'status' => 'awaiting_payment',
                'order_type' => 'course',
                'subtotal' => $product->price,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'tax_amount' => 0,
                'total' => $product->price,
                'currency' => $product->currency ?? 'EGP',
                'billing_address' => [],
                'shipping_address' => [],
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $label,
                'product_sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => $product->price,
                'total_price' => $product->price,
                'metadata' => [
                    'product_type' => $product->type?->value,
                    'course_id' => $product->course_id,
                    'course_plan_id' => $product->course_plan_id,
                ],
            ]);

            return $order->load('items');
        });
    }
}
