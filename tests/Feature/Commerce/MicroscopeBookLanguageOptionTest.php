<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Support\BostaPackageDetailsBuilder;
use App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MicroscopeBookLanguageOptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'sciencestreet.frontend_url' => 'https://app.example.test',
        ]);
    }

    public function test_microscope_without_book_language_rejected(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book01')
            ->postJson('/api/v1/cart/items', ['product_id' => $micro->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MICROSCOPE_BOOK_LANGUAGE_REQUIRED');
    }

    public function test_microscope_ar_and_en_pass_and_remain_distinct(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book02')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])->assertCreated();

        $this->withHeader('X-Cart-Session', 'book02')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'quantity' => 1,
                'book_language' => 'en',
            ])->assertCreated();

        $cart = $this->withHeader('X-Cart-Session', 'book02')->getJson('/api/v1/cart')->assertOk();
        $this->assertCount(2, $cart->json('data.items'));
        $langs = collect($cart->json('data.items'))
            ->pluck('metadata.book_language')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['ar', 'en'], $langs);
    }

    public function test_same_option_merges_quantity(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book03')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])->assertCreated();
        $this->withHeader('X-Cart-Session', 'book03')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])->assertCreated();

        $cart = $this->withHeader('X-Cart-Session', 'book03')->getJson('/api/v1/cart')->assertOk();
        $this->assertCount(1, $cart->json('data.items'));
        $this->assertSame(2, (int) $cart->json('data.items.0.quantity'));
        $this->assertSame('ar', $cart->json('data.items.0.metadata.book_language'));
    }

    public function test_invalid_book_language_rejected(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book04')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'fr',
            ])->assertStatus(422);
    }

    public function test_unrelated_product_without_book_language_unchanged(): void
    {
        $other = Product::query()->create([
            'sku' => 'OTHER-'.uniqid(),
            'slug' => 'other-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 50,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'كورس'],
        ]);

        $this->withHeader('X-Cart-Session', 'book05')
            ->postJson('/api/v1/cart/items', ['product_id' => $other->id])
            ->assertCreated();
    }

    public function test_update_remove_isolate_language_rows(): void
    {
        $micro = $this->microscope();
        $ar = $this->withHeader('X-Cart-Session', 'book06')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'ar',
            ])->assertCreated()->json('data.id');
        $en = $this->withHeader('X-Cart-Session', 'book06')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'en',
            ])->assertCreated()->json('data.id');

        $this->withHeader('X-Cart-Session', 'book06')
            ->putJson('/api/v1/cart/items/'.$en, ['quantity' => 3])
            ->assertOk();

        $this->assertSame(1, (int) CartItem::query()->find($ar)?->quantity);
        $this->assertSame(3, (int) CartItem::query()->find($en)?->quantity);

        $this->withHeader('X-Cart-Session', 'book06')
            ->deleteJson('/api/v1/cart/items/'.$ar)
            ->assertOk();

        $this->assertNull(CartItem::query()->find($ar));
        $this->assertNotNull(CartItem::query()->find($en));
        $this->assertSame('en', CartItem::query()->find($en)?->metadata['book_language'] ?? null);
    }

    public function test_checkout_blocks_legacy_microscope_without_language(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book07')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'ar',
            ])->assertCreated();

        $item = CartItem::query()->firstOrFail();
        $item->update(['metadata' => null, 'options_key' => '']);

        $this->withHeader('X-Cart-Session', 'book07')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertStatus(422);
    }

    public function test_order_snapshot_stores_book_language_and_bosta_description(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book08')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'ar',
            ])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'book08')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();

        $orderId = (int) $checkout->json('data.id');
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('ar', $item->metadata['book_language'] ?? null);

        $order = Order::query()->with('items')->findOrFail($orderId);
        $details = app(BostaPackageDetailsBuilder::class)->build($order);
        $this->assertStringContainsString('Book: عربي', $details['description']);
    }

    public function test_historical_order_without_book_language_renders_safely(): void
    {
        $order = Order::query()->create([
            'user_id' => null,
            'is_guest' => true,
            'status' => 'paid',
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'currency' => 'EGP',
            'billing_address' => $this->billing(),
            'shipping_address' => $this->billing(),
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name' => 'Old Microscope',
            'product_sku' => 'SS-MICRO-001',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'metadata' => ['product_type' => 'kit'],
        ]);

        $this->assertNull(MicroscopePurchaseOptions::bookLanguageFromMetadata(['product_type' => 'kit']));
        $details = app(BostaPackageDetailsBuilder::class)->build($order->fresh(['items']));
        $this->assertSame('Old Microscope', $details['description']);
    }

    public function test_authenticated_my_orders_includes_metadata(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $micro = $this->microscope();

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $micro->id,
            'book_language' => 'en',
        ])->assertCreated();

        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => $this->billing(['email' => $user->email]),
            'shipping_address' => $this->billing(['email' => $user->email]),
        ])->assertCreated();

        $orderNumber = $checkout->json('data.order_number');
        $this->getJson('/api/v1/orders/'.$orderNumber)
            ->assertOk()
            ->assertJsonPath('data.items.0.metadata.book_language', 'en');
    }

    public function test_legacy_invalid_microscope_line_upgraded_on_add_with_language(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book-legacy')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'ar',
            ])->assertCreated();

        $item = CartItem::query()->firstOrFail();
        $item->update(['metadata' => null, 'options_key' => '']);

        $this->withHeader('X-Cart-Session', 'book-legacy')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.metadata.book_language', null);

        $this->withHeader('X-Cart-Session', 'book-legacy')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])->assertCreated();

        $cart = $this->withHeader('X-Cart-Session', 'book-legacy')
            ->getJson('/api/v1/cart')
            ->assertOk();

        $this->assertCount(1, $cart->json('data.items'));
        $this->assertSame('ar', $cart->json('data.items.0.metadata.book_language'));
        $this->assertSame(2, (int) $cart->json('data.items.0.quantity'));
        $this->assertSame($item->id, (int) $cart->json('data.items.0.id'));
    }

    public function test_guest_auth_merge_preserves_book_language(): void
    {
        $micro = $this->microscope();
        $this->withHeader('X-Cart-Session', 'book-merge')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $micro->id,
                'book_language' => 'en',
            ])->assertCreated();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        app(\App\Modules\Commerce\Application\Services\CartService::class)
            ->mergeSessionCartIntoUserCart($user, 'book-merge');

        $cart = $this->getJson('/api/v1/cart')->assertOk();
        $this->assertCount(1, $cart->json('data.items'));
        $this->assertSame('en', $cart->json('data.items.0.metadata.book_language'));
    }

    private function microscope(): Product
    {
        return Product::query()->create([
            'sku' => MicroscopePurchaseOptions::SKU,
            'slug' => MicroscopePurchaseOptions::SLUG,
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 2790,
            'currency' => 'EGP',
            'free_shipping' => true,
            'published_at' => now(),
            'name' => ['en' => 'Science Street Microscope', 'ar' => 'ميكروسكوب شارع العلوم'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function billing(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'email' => 'guest@example.com',
            'phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '123 Test Street Nasr',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ], $overrides);
    }
}
