<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Support\DeliveryAddressValidator;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CheckoutDeliveryAddressMinLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.api_contract_ready' => false,
            'commerce.payment_gateway' => 'mock',
        ]);

        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_empty_address_is_rejected(): void
    {
        $this->checkoutWithAddress('')->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_address.address']);
    }

    public function test_short_arabic_address_is_rejected(): void
    {
        $response = $this->checkoutWithAddress('شارع قصير')->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_address.address']);

        $this->assertSame(
            DeliveryAddressValidator::MESSAGE,
            $response->json('errors')['shipping_address.address'][0] ?? null
        );
    }

    public function test_exactly_fifteen_characters_accepted(): void
    {
        // 15 Arabic letters
        $address = 'شارع التحريرااا';
        $this->assertSame(15, mb_strlen($address));

        $this->checkoutWithAddress($address)->assertCreated();
    }

    public function test_longer_than_fifteen_accepted(): void
    {
        $this->checkoutWithAddress('شارع التحرير رقم المبنى ١٢ الدور ٣')->assertCreated();
    }

    public function test_leading_trailing_spaces_do_not_satisfy_minimum(): void
    {
        // 9 meaningful chars padded to look long
        $raw = '   شارع قصير   ';
        $this->assertTrue(mb_strlen($raw) >= 15);
        $this->assertSame(9, mb_strlen(trim($raw)));

        $response = $this->checkoutWithAddress($raw)->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_address.address']);

        $this->assertSame(
            DeliveryAddressValidator::MESSAGE,
            $response->json('errors')['shipping_address.address'][0] ?? null
        );
    }

    public function test_whitespace_only_address_rejected(): void
    {
        $this->checkoutWithAddress(str_repeat(' ', 20))->assertStatus(422);
    }

    public function test_english_address_works(): void
    {
        $this->checkoutWithAddress('12 Liberation Street')->assertCreated();
    }

    public function test_arabic_address_works(): void
    {
        $this->checkoutWithAddress('اسم الشارع والمبنى والدور')->assertCreated();
    }

    public function test_api_bypass_with_short_address_returns_422_field_error(): void
    {
        $response = $this->checkoutWithAddress('short');
        $response->assertStatus(422);
        $this->assertArrayHasKey('shipping_address.address', $response->json('errors') ?? []);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_valid_checkout_continues_and_persists_trimmed_address(): void
    {
        $raw = '  شارع التحرير مبنى ٥ دور ٢  ';
        $response = $this->checkoutWithAddress($raw)->assertCreated();
        $order = Order::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(trim($raw), $order->shipping_address['address'] ?? null);
    }

    public function test_bosta_receives_validated_trimmed_address(): void
    {
        $captured = (object) ['address' => null];
        $this->app->instance(
            \App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class,
            new class($captured) implements \App\Modules\Commerce\Domain\Contracts\BostaClientInterface
            {
                public function __construct(private object $captured) {}

                public function createShipment(\App\Modules\Commerce\Infrastructure\Persistence\Models\Order $order): array
                {
                    $this->captured->address = $order->shipping_address['address'] ?? null;

                    return [
                        'external_shipment_id' => 'bosta-addr-1',
                        'tracking_number' => 'TRACK-ADDR-1',
                        'tracking_url' => null,
                        'provider_status' => 'created',
                        'raw' => [],
                    ];
                }
            }
        );

        $address = '  شارع التحرير مبنى ١٠  ';
        $this->app->forgetInstance(\App\Modules\Commerce\Application\Services\BostaShipmentService::class);
        $this->app->forgetInstance(\App\Modules\Commerce\Application\Services\CheckoutService::class);
        $this->checkoutWithAddress($address, paymentMethod: 'cod')->assertCreated();

        $this->assertSame(trim($address), $captured->address);
        $this->assertGreaterThanOrEqual(
            DeliveryAddressValidator::MIN_LENGTH,
            mb_strlen((string) $captured->address)
        );
    }

    public function test_digital_only_checkout_does_not_require_street_address_min(): void
    {
        [$user, $product] = $this->digitalProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $this->postJson('/api/v1/checkout', [
            'payment_method' => 'online',
            'billing_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => '01012345678',
            ],
        ])->assertCreated();
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function checkoutWithAddress(string $address, string $paymentMethod = 'online')
    {
        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $base = $this->address($address);

        return $this->postJson('/api/v1/checkout', [
            'payment_method' => $paymentMethod,
            'billing_address' => $base,
            'shipping_address' => $base,
        ]);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function kitProduct(): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'sku' => 'KIT-ADDR-'.uniqid(),
            'slug' => 'kit-addr-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Kit', 'ar' => 'عدة'],
        ]);

        return [$user, $product];
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function digitalProduct(): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'sku' => 'DIG-ADDR-'.uniqid(),
            'slug' => 'dig-addr-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 50,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'دورة'],
        ]);

        return [$user, $product];
    }

    private function addToCart(Product $product): void
    {
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function address(string $line): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '01012345678',
            'city' => 'Giza',
            'country' => 'EG',
            'address' => $line,
            'district' => 'ElSaf',
            'district_name' => 'ElSaf',
            'district_id' => 'ULL4DLjuJ3t',
            'bosta_district_id' => 'ULL4DLjuJ3t',
            'bosta_city_id' => '0064Qb0OgcA',
            'zone_id' => 'gl7gjgDSq5N',
            'bosta_zone_id' => 'gl7gjgDSq5N',
        ];
    }
}
