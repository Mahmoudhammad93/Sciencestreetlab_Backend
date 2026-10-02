<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BostaLocationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => false,
            'bosta.api_contract_ready' => true,
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_key' => 'test-bosta-api-key-not-real',
            'bosta.http_retries' => 0,
        ]);

        Cache::flush();
    }

    public function test_cities_endpoint_returns_safe_fields_and_supports_search(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [
                    ['_id' => '0064Qb0OgcA', 'name' => 'Giza', 'nameAr' => 'الجيزه', 'code' => 'EG-25', 'dropOffAvailability' => true],
                    ['_id' => 'city-cairo', 'name' => 'Cairo', 'nameAr' => 'القاهره', 'code' => 'EG-01', 'dropOffAvailability' => true],
                    ['_id' => 'secret-city', 'name' => 'Hidden', 'nameAr' => 'مخفي', 'dropOffAvailability' => false],
                ]],
            ], 200),
        ]);

        $all = $this->getJson('/api/v1/shipping/locations/cities')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertSame(['id', 'name', 'name_ar', 'code'], array_keys($all->json('data.0')));
        $body = $all->getContent();
        $this->assertStringNotContainsString('test-bosta-api-key-not-real', $body);
        $this->assertStringNotContainsString('api_key', $body);

        $search = $this->getJson('/api/v1/shipping/locations/cities?search=جيز')->assertOk();
        $this->assertCount(1, $search->json('data'));
        $this->assertSame('0064Qb0OgcA', $search->json('data.0.id'));
        $this->assertSame('Giza', $search->json('data.0.name'));
    }

    public function test_districts_endpoint_filters_by_search_and_rejects_invalid_city(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities/0064Qb0OgcA/districts' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'districtId' => 'gl7gjgDSq5N',
                        'districtName' => 'Arab ElHesar',
                        'districtOtherName' => 'عرب الحصار',
                        'zoneId' => 'ULL4DLjuJ3t',
                        'zoneName' => 'ElSaf',
                        'zoneOtherName' => 'الصف',
                        'dropOffAvailability' => true,
                    ],
                    [
                        'districtId' => 'other',
                        'districtName' => 'Dokki',
                        'districtOtherName' => 'الدقي',
                        'zoneId' => 'z1',
                        'zoneName' => 'Dokki',
                        'dropOffAvailability' => true,
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/shipping/locations/cities/bad%20city!/districts')
            ->assertNotFound();

        $filtered = $this->getJson('/api/v1/shipping/locations/cities/0064Qb0OgcA/districts?search=hesar')
            ->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('gl7gjgDSq5N', $filtered->json('data.0.id'));
        $this->assertSame('ULL4DLjuJ3t', $filtered->json('data.0.zone_id'));
        $this->assertStringNotContainsString('test-bosta-api-key-not-real', $filtered->getContent());
    }

    public function test_legacy_bosta_cities_alias_still_works(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [
                    ['_id' => 'c1', 'name' => 'Cairo', 'nameAr' => 'القاهره', 'dropOffAvailability' => true],
                ]],
            ], 200),
        ]);

        $this->getJson('/api/v1/shipping/bosta/cities')->assertOk()->assertJsonPath('data.0.name', 'Cairo');
    }
}
