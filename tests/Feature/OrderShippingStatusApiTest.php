<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrderShippingStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.webhook_secret' => 'test-secret',
            'bosta.api_contract_ready' => false,
            'bosta.webhook_signature_ready' => false,
        ]);
    }

    public function test_shipping_status_returned_for_bosta_order(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.shipping.required', true)
            ->assertJsonPath('data.shipping.provider', 'bosta')
            ->assertJsonPath('data.shipping.status', 'created')
            ->assertJsonPath('data.shipping.status_label', 'Shipment Created')
            ->assertJsonPath('data.shipping.course_access.status', 'locked')
            ->assertJsonPath('data.shipping.course_access.unlocks_on', 'delivered')
            ->assertJsonCount(7, 'data.shipping.steps')
            ->assertJsonMissingPath('data.bosta_shipment')
            ->assertJsonMissingPath('data.shipping.metadata');
    }

    public function test_created_shipment_marks_shipment_created_as_current(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertStepStates($payload['steps'], [
            'order_placed' => 'completed',
            'shipment_created' => 'current',
            'picked_up' => 'pending',
            'in_transit' => 'pending',
            'out_for_delivery' => 'pending',
            'delivered' => 'pending',
            'course_activated' => 'pending',
        ]);
        $this->assertSame(2, $payload['progress']['current_step']);
        $this->assertSame(7, $payload['progress']['total_steps']);
        $this->assertSame(14, $payload['progress']['percentage']); // 1/7 completed
        $this->assertSame('locked', $payload['course_access']['status']);
    }

    public function test_picked_up_marks_picked_up_as_current(): void
    {
        $this->assertJourneyStep(ShipmentStatus::PickedUp, 'picked_up', 'locked', 3, 29);
    }

    public function test_in_transit_marks_in_transit_as_current(): void
    {
        $this->assertJourneyStep(ShipmentStatus::InTransit, 'in_transit', 'locked', 4, 43);
    }

    public function test_out_for_delivery_marks_out_for_delivery_as_current(): void
    {
        $this->assertJourneyStep(ShipmentStatus::OutForDelivery, 'out_for_delivery', 'locked', 5, 57);
    }

    public function test_delivered_marks_delivered_completed_and_course_processing(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $order->bostaShipment->update([
            'status' => ShipmentStatus::Delivered,
            'delivered_at' => now(),
            'shipped_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame('delivered', $payload['status']);
        $this->assertSame('processing', $payload['course_access']['status']);
        $this->assertStepStates($payload['steps'], [
            'order_placed' => 'completed',
            'shipment_created' => 'completed',
            'picked_up' => 'completed',
            'in_transit' => 'completed',
            'out_for_delivery' => 'completed',
            'delivered' => 'completed',
            'course_activated' => 'current',
        ]);
        $this->assertSame(7, $payload['progress']['current_step']);
        $this->assertSame(86, $payload['progress']['percentage']); // 6/7 completed
        $this->assertNotNull(
            collect($payload['steps'])->firstWhere('key', 'delivered')['completed_at']
        );
    }

    public function test_fulfilled_marks_course_activated_completed(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'delivered',
        ]);

        $order->refresh();
        $this->assertNotNull($order->fulfilled_at);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame('active', $payload['course_access']['status']);
        $this->assertStepStates($payload['steps'], [
            'order_placed' => 'completed',
            'shipment_created' => 'completed',
            'picked_up' => 'completed',
            'in_transit' => 'completed',
            'out_for_delivery' => 'completed',
            'delivered' => 'completed',
            'course_activated' => 'completed',
        ]);
        $this->assertSame(100, $payload['progress']['percentage']);
        $this->assertNotNull(
            collect($payload['steps'])->firstWhere('key', 'course_activated')['completed_at']
        );
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_before_delivered_course_access_is_locked(): void
    {
        foreach ([
            ShipmentStatus::Created,
            ShipmentStatus::PickedUp,
            ShipmentStatus::InTransit,
            ShipmentStatus::OutForDelivery,
        ] as $status) {
            [$user, , $product] = $this->kitWithCourse();
            $order = $this->checkoutKit($user, $product);
            $order->bostaShipment->update(['status' => $status]);

            Sanctum::actingAs($user);
            $this->getJson('/api/v1/orders/'.$order->order_number)
                ->assertOk()
                ->assertJsonPath('data.shipping.course_access.status', 'locked');
        }
    }

    public function test_delivered_and_fulfilled_course_access_is_active(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'delivered',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.shipping.course_access.status', 'active')
            ->assertJsonPath('data.shipping.steps.6.status', 'completed');
    }

    public function test_cancelled_keeps_course_locked(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $order->bostaShipment->update(['status' => ShipmentStatus::Cancelled]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame('cancelled', $payload['status']);
        $this->assertSame('locked', $payload['course_access']['status']);
        $this->assertTrue($payload['progress']['halted']);
        $this->assertSame('cancelled', $payload['progress']['halt_reason']);
        $this->assertSame('failed', collect($payload['steps'])->firstWhere('key', 'picked_up')['status']);
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_failed_keeps_course_locked(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $order->bostaShipment->update(['status' => ShipmentStatus::Failed]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame('failed', $payload['status']);
        $this->assertSame('locked', $payload['course_access']['status']);
        $this->assertTrue($payload['progress']['halted']);
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_unknown_never_unlocks_course_or_advances_to_delivered(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $order->bostaShipment->update(['status' => ShipmentStatus::Unknown]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame('unknown', $payload['status']);
        $this->assertSame('locked', $payload['course_access']['status']);
        $this->assertSame('Status Updating', $payload['status_label']);
        $this->assertStepStates($payload['steps'], [
            'order_placed' => 'completed',
            'shipment_created' => 'current',
            'picked_up' => 'pending',
            'in_transit' => 'pending',
            'out_for_delivery' => 'pending',
            'delivered' => 'pending',
            'course_activated' => 'pending',
        ]);
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_shipping_contract_fields_present_on_show_and_index(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        Sanctum::actingAs($user);

        foreach ([
            $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping'),
            $this->getJson('/api/v1/orders')->assertOk()->json('data.0.shipping'),
        ] as $shipping) {
            foreach ([
                'required', 'provider', 'status', 'status_label',
                'tracking_number', 'tracking_url', 'shipped_at', 'delivered_at',
                'course_access', 'progress', 'steps',
            ] as $key) {
                $this->assertArrayHasKey($key, $shipping);
            }

            foreach (['status', 'unlocks_on', 'message'] as $key) {
                $this->assertArrayHasKey($key, $shipping['course_access']);
            }

            foreach (['current_step', 'total_steps', 'percentage', 'halted', 'halt_reason'] as $key) {
                $this->assertArrayHasKey($key, $shipping['progress']);
            }

            $this->assertNotEmpty($shipping['steps']);
            foreach ($shipping['steps'] as $step) {
                foreach (['key', 'label', 'status', 'completed_at'] as $key) {
                    $this->assertArrayHasKey($key, $step);
                }
            }

            $this->assertArrayNotHasKey('provider_status', $shipping);
            $this->assertArrayNotHasKey('metadata', $shipping);
        }
    }

    public function test_duplicate_delivered_webhook_does_not_duplicate_fulfillment(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        $service = app(BostaWebhookService::class);
        $service->handle(['external_shipment_id' => $externalId, 'status' => 'delivered']);
        $service->handle(['external_shipment_id' => $externalId, 'status' => 'delivered']);

        $this->assertSame(1, $order->fresh()->fulfilled_at !== null ? 1 : 0);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_tracking_url_returned_only_when_persisted(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        Sanctum::actingAs($user);
        $withUrl = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');
        $this->assertNotNull($withUrl['tracking_url']);
        $this->assertNotNull($withUrl['tracking_number']);

        $order->bostaShipment->update([
            'tracking_url' => null,
            'tracking_number' => null,
        ]);

        $withoutUrl = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');
        $this->assertNull($withoutUrl['tracking_url']);
        $this->assertNull($withoutUrl['tracking_number']);
    }

    public function test_another_user_cannot_access_order_shipping(): void
    {
        [$owner, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($owner, $product);
        $intruder = User::factory()->create();

        Sanctum::actingAs($intruder);
        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertNotFound();
    }

    public function test_api_response_does_not_expose_metadata_or_secrets(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $order->bostaShipment->update([
            'metadata' => [
                'last_webhook_payload' => ['secret' => 'should-not-leak', 'status' => 'in_transit'],
                'raw' => ['api_key' => 'x'],
            ],
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk();
        $json = $response->json();

        $encoded = json_encode($json);
        $this->assertStringNotContainsString('should-not-leak', $encoded);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('last_webhook_payload', $encoded);
        $this->assertArrayNotHasKey('bosta_shipment', $json['data']);
        $this->assertArrayNotHasKey('metadata', $json['data']['shipping']);
    }

    public function test_orders_index_includes_shipping_for_owner(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $this->checkoutKit($user, $product);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.shipping.required', true)
            ->assertJsonPath('data.0.shipping.course_access.unlocks_on', 'delivered');
    }

    public function test_arabic_status_labels_via_accept_language(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $order->bostaShipment->update(['status' => ShipmentStatus::InTransit]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/'.$order->order_number, ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.shipping.status_label', 'الشحنة في الطريق')
            ->assertJsonPath('data.shipping.steps.3.label', 'الشحنة في الطريق');
    }

    /**
     * @param  array<string, string>  $expected
     * @param  list<array{key: string, status: string}>  $steps
     */
    private function assertStepStates(array $steps, array $expected): void
    {
        $byKey = collect($steps)->keyBy('key');
        foreach ($expected as $key => $status) {
            $this->assertSame($status, $byKey[$key]['status'], "Step {$key}");
        }
    }

    private function assertJourneyStep(
        ShipmentStatus $status,
        string $currentKey,
        string $courseAccess,
        int $currentStep,
        int $percentage,
    ): void {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $order->bostaShipment->update([
            'status' => $status,
            'shipped_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data.shipping');

        $this->assertSame($courseAccess, $payload['course_access']['status']);
        $this->assertSame($currentStep, $payload['progress']['current_step']);
        $this->assertSame(7, $payload['progress']['total_steps']);
        $this->assertSame($percentage, $payload['progress']['percentage']);
        $this->assertSame(
            'current',
            collect($payload['steps'])->firstWhere('key', $currentKey)['status']
        );
    }

    /**
     * @return array{0: User, 1: Course, 2: Product}
     */
    private function kitWithCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'kit-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 250,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        return [$user, $course, $product];
    }

    private function cartWithProduct(User $user, Product $product): Cart
    {
        $cart = Cart::query()->create(['user_id' => $user->id]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        return $cart->fresh(['items.product']);
    }

    private function checkoutKit(User $user, Product $product): Order
    {
        return app(CheckoutService::class)->createOrderFromCart(
            $user,
            $this->cartWithProduct($user, $product),
            ['first_name' => $user->name, 'email' => $user->email],
            ['city' => 'Cairo'],
        );
    }
}
