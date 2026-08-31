<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_checkout_and_receive_course_enrollment_after_mock_payment(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();

        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Ahmed',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => [
                'city' => 'Cairo',
                'country' => 'EG',
            ],
        ])->assertCreated();

        $orderId = $checkout->json('data.id');

        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay")->assertOk();
        $paymentId = $pay->json('data.payment_id');

        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'paid',
            'user_id' => $user->id,
        ]);

        $courseId = $product->fresh()->course_id;
        $this->assertNotNull($courseId);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $courseId,
        ]);

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->count());
    }

    public function test_coupon_reduces_checkout_total(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'SCIENCE10'])->assertOk();

        $cart = $this->getJson('/api/v1/cart')->assertOk();
        $this->assertGreaterThan(0, $cart->json('data.discount'));
    }

    public function test_checkout_increments_coupon_used_count(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $coupon = Coupon::query()
            ->where('code', 'SCIENCE10')
            ->firstOrFail();

        $coupon->update(['max_uses' => 2, 'used_count' => 0]);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();
        $this->postJson('/api/v1/cart/coupon', ['code' => 'SCIENCE10'])->assertOk();

        $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Ahmed',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->assertCreated();

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_coupon_cannot_be_applied_after_max_uses_reached(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $coupon = Coupon::query()
            ->where('code', 'SCIENCE10')
            ->firstOrFail();

        $coupon->update(['max_uses' => 1, 'used_count' => 1]);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();

        $this->postJson('/api/v1/cart/coupon', ['code' => 'SCIENCE10'])
            ->assertStatus(422);
    }
}
