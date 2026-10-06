<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Models\User;
use App\Modules\Commerce\Application\Services\BostaShipmentReconciliationService;
use App\Modules\Commerce\Application\Services\BostaShipmentStatusService;
use App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class OrderEditPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_historical_order_without_book_language_loads(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'ميكروسكوب شارع العلوم',
            'metadata' => [
                'course_id' => 26,
                'product_type' => 'kit',
                'free_shipping' => true,
                'course_plan_id' => 7,
            ],
        ]);

        $this->assertNull(MicroscopePurchaseOptions::bookLanguageFromMetadata($order->items->first()?->metadata));

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('ميكروسكوب شارع العلوم');
    }

    public function test_microscope_order_book_language_ar_visible(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'ميكروسكوب شارع العلوم',
            'metadata' => ['book_language' => 'ar', 'product_type' => 'kit'],
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('عربي');
    }

    public function test_microscope_order_book_language_en_visible(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'Science Street Microscope',
            'metadata' => ['book_language' => 'en', 'product_type' => 'kit'],
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('English');
    }

    public function test_order_without_shipment_loads(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'Course access',
            'metadata' => ['product_type' => 'course'],
            'requires_delivery_fulfillment' => false,
        ]);

        $this->assertNull($order->bostaShipment);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_order_with_bosta_shipment_and_nullable_sync_fields_loads(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'ميكروسكوب شارع العلوم',
            'metadata' => ['book_language' => 'ar'],
            'requires_delivery_fulfillment' => true,
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'provider' => ShipmentProvider::Bosta,
            'external_shipment_id' => 'ext-test-1',
            'tracking_number' => 'TRK-1',
            'status' => ShipmentStatus::InTransit,
            'provider_status' => '24',
            'last_webhook_at' => null,
            'metadata' => [
                // intentionally omit last_status_source / last_status_synced_at
            ],
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('TRK-1')
            ->assertSee('ext-test-1');
    }

    public function test_filament_bosta_sync_action_can_resolve_reconciliation_service(): void
    {
        $this->assertTrue(class_exists(BostaShipmentReconciliationService::class));
        $this->assertTrue(class_exists(BostaShipmentStatusService::class));

        $service = app(BostaShipmentReconciliationService::class);
        $this->assertInstanceOf(BostaShipmentReconciliationService::class, $service);
        $this->assertInstanceOf(BostaShipmentStatusService::class, app(BostaShipmentStatusService::class));

        $order = $this->makeOrder([
            'product_name' => 'ميكروسكوب شارع العلوم',
            'metadata' => ['book_language' => 'ar'],
            'requires_delivery_fulfillment' => true,
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'provider' => ShipmentProvider::Bosta,
            'external_shipment_id' => 'ext-sync-resolve-1',
            'tracking_number' => 'TRK-SYNC-1',
            'status' => ShipmentStatus::InTransit,
            'provider_status' => '24',
            'metadata' => [],
        ]);

        // Loads EditOrder (which type-hints the reconciliation service for syncBostaStatus)
        // without BindingResolutionException — proves Filament action dependency is deployable.
        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('syncBostaStatus');
    }

    public function test_order_2648_equivalent_fixture_loads(): void
    {
        $order = $this->makeOrder([
            'order_number' => 'SS-SXPJ6ZQA',
            'status' => 'paid',
            'total' => 13.95,
            'product_name' => 'ميكروسكوب شارع العلوم',
            'metadata' => [
                'course_id' => 26,
                'product_type' => 'kit',
                'free_shipping' => true,
                'course_plan_id' => 7,
            ],
            'requires_delivery_fulfillment' => true,
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'provider' => ShipmentProvider::Bosta,
            'external_shipment_id' => 'FoRlSY9FwgS6e5CiJJ0Y4',
            'tracking_number' => '133569626',
            'status' => ShipmentStatus::InTransit,
            'provider_status' => '24',
            'metadata' => ['last_status_source' => 'reconciliation'],
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSet('data.order_number', 'SS-SXPJ6ZQA')
            ->assertSee('ميكروسكوب شارع العلوم')
            ->assertSee('133569626');
    }

    public function test_malformed_optional_metadata_renders_gracefully(): void
    {
        $order = $this->makeOrder([
            'product_name' => 'Kit item',
            'metadata' => [
                'book_language' => ['not' => 'a-string'],
                'extra' => new \stdClass(),
            ],
        ]);

        // Force metadata to a mixed array after cast by updating raw JSON-compatible values
        $item = $order->items->first();
        $item?->update([
            'metadata' => [
                'book_language' => 123,
                'weird' => true,
            ],
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Kit item')
            ->assertDontSee('عربي')
            ->assertDontSee('English');
    }

    public function test_user_without_admin_role_cannot_open_order_edit(): void
    {
        $order = $this->makeOrder(['product_name' => 'X', 'metadata' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/orders/'.$order->getRouteKey().'/edit')
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    /**
     * @param  array{
     *   order_number?: string,
     *   status?: string,
     *   total?: float|int|string,
     *   product_name: string,
     *   metadata?: array<string, mixed>|null,
     *   requires_delivery_fulfillment?: bool
     * }  $attrs
     */
    private function makeOrder(array $attrs): Order
    {
        $order = Order::query()->create([
            'order_number' => $attrs['order_number'] ?? ('SS-TEST-'.strtoupper(substr(md5((string) microtime(true)), 0, 6))),
            'user_id' => null,
            'is_guest' => true,
            'status' => $attrs['status'] ?? 'paid',
            'order_type' => 'product',
            'subtotal' => $attrs['total'] ?? 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => $attrs['total'] ?? 100,
            'currency' => 'EGP',
            'billing_address' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'test@example.com',
                'phone' => '01000000000',
            ],
            'shipping_address' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'phone' => '01000000000',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => 'Test address line',
            ],
            'requires_delivery_fulfillment' => $attrs['requires_delivery_fulfillment'] ?? false,
            'paid_at' => now(),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name' => $attrs['product_name'],
            'product_sku' => MicroscopePurchaseOptions::SKU,
            'quantity' => 1,
            'unit_price' => $attrs['total'] ?? 100,
            'total_price' => $attrs['total'] ?? 100,
            'metadata' => $attrs['metadata'] ?? null,
        ]);

        return $order->fresh(['items', 'bostaShipment']) ?? $order;
    }
}
