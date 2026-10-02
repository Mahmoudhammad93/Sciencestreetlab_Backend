<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Domain\Enums\ShippingLocationScope;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\ShippingRateLocation;
use DomainException;

/**
 * Authoritative destination shipping pricing.
 *
 * Resolution order (most specific wins):
 * 1) district/zone override (if configured)
 * 2) city rate
 * 3) no rate → checkout blocked (never silent 0)
 */
final class ShippingPricingService
{
    /**
     * @param  array<string, mixed>|null  $shippingAddress
     * @return array{
     *     amount: float,
     *     currency: string,
     *     free: bool,
     *     label: string,
     *     reason: string,
     *     rate_group_id: int|null,
     *     rate_code: string|null,
     *     rate_name: array<string, string>|null,
     *     resolution: string,
     *     bosta_city_id: string|null,
     *     bosta_district_id: string|null,
     *     bosta_zone_id: string|null,
     *     requires_paid_shipping: bool
     * }
     */
    public function quoteForCart(Cart $cart, ?array $shippingAddress): array
    {
        $cart->loadMissing('items.product');

        if (! $this->cartRequiresPaidShipping($cart)) {
            return $this->zeroQuote(
                reason: $this->cartHasPhysicalItems($cart) ? 'all_products_free_shipping' : 'digital_only',
                free: true,
                shippingAddress: $shippingAddress,
            );
        }

        if ($shippingAddress === null || $shippingAddress === []) {
            return $this->pendingQuote(null);
        }

        $cityId = $this->stringField($shippingAddress, [
            'bosta_city_id', 'city_id', 'cityId',
        ]);
        if ($cityId === '') {
            return $this->pendingQuote($shippingAddress);
        }

        return $this->quoteForDestination($shippingAddress, requiresPaidShipping: true);
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     * @return array{
     *     amount: float,
     *     currency: string,
     *     free: bool,
     *     label: string,
     *     reason: string,
     *     rate_group_id: int|null,
     *     rate_code: string|null,
     *     rate_name: array<string, string>|null,
     *     resolution: string,
     *     bosta_city_id: string|null,
     *     bosta_district_id: string|null,
     *     bosta_zone_id: string|null,
     *     requires_paid_shipping: bool
     * }
     */
    public function quoteForDestination(array $shippingAddress, bool $requiresPaidShipping = true): array
    {
        $cityId = $this->stringField($shippingAddress, [
            'bosta_city_id', 'city_id', 'cityId',
        ]);
        $districtId = $this->stringField($shippingAddress, [
            'bosta_district_id', 'district_id', 'districtId',
        ]);
        $zoneId = $this->stringField($shippingAddress, [
            'bosta_zone_id', 'zone_id', 'zoneId',
        ]);

        $location = null;
        $resolution = null;

        if ($districtId !== '') {
            $location = $this->activeLocation(ShippingLocationScope::District, $districtId);
            if ($location !== null) {
                $resolution = 'district';
            }
        }

        if ($location === null && $zoneId !== '') {
            $location = $this->activeLocation(ShippingLocationScope::Zone, $zoneId);
            if ($location !== null) {
                $resolution = 'zone';
            }
        }

        if ($location === null && $cityId !== '') {
            $location = $this->activeLocation(ShippingLocationScope::City, $cityId);
            if ($location !== null) {
                $resolution = 'city';
            }
        }

        if ($location === null || $location->group === null || ! $location->group->is_active) {
            throw new DomainException(
                'سعر التوصيل لهذه المنطقة غير متاح حاليًا، يرجى التواصل معنا.'
            );
        }

        $group = $location->group;
        $amount = round((float) $group->price, 2);
        $name = $group->getTranslations('name');

        return [
            'amount' => $amount,
            'currency' => 'EGP',
            'free' => $amount <= 0,
            'label' => (string) ($name['ar'] ?? $name['en'] ?? $group->code),
            'reason' => 'destination_rate',
            'rate_group_id' => $group->id,
            'rate_code' => $group->code,
            'rate_name' => $name,
            'resolution' => (string) $resolution,
            'bosta_city_id' => $cityId !== '' ? $cityId : null,
            'bosta_district_id' => $districtId !== '' ? $districtId : null,
            'bosta_zone_id' => $zoneId !== '' ? $zoneId : null,
            'requires_paid_shipping' => $requiresPaidShipping,
        ];
    }

    public function cartRequiresPaidShipping(Cart $cart): bool
    {
        $cart->loadMissing('items.product');

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->productIsPhysical($product)) {
                continue;
            }
            if (! (bool) ($product->free_shipping ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function cartHasPhysicalItems(Cart $cart): bool
    {
        $cart->loadMissing('items.product');

        foreach ($cart->items as $item) {
            if ($item->product instanceof Product && $this->productIsPhysical($item->product)) {
                return true;
            }
        }

        return false;
    }

    public function productIsPhysical(Product $product): bool
    {
        $type = $product->type;

        return $type === ProductType::Kit || $type === ProductType::Bundle;
    }

    private function activeLocation(ShippingLocationScope $scope, string $locationId): ?ShippingRateLocation
    {
        return ShippingRateLocation::query()
            ->with('group')
            ->where('scope_type', $scope->value)
            ->where('bosta_location_id', $locationId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     */
    private function stringField(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }
            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $shippingAddress
     * @return array{
     *     amount: float,
     *     currency: string,
     *     free: bool,
     *     label: string,
     *     reason: string,
     *     rate_group_id: int|null,
     *     rate_code: string|null,
     *     rate_name: array<string, string>|null,
     *     resolution: string,
     *     bosta_city_id: string|null,
     *     bosta_district_id: string|null,
     *     bosta_zone_id: string|null,
     *     requires_paid_shipping: bool
     * }
     */
    private function pendingQuote(?array $shippingAddress): array
    {
        return [
            'amount' => 0.0,
            'currency' => 'EGP',
            'free' => false,
            'label' => '',
            'reason' => 'destination_pending',
            'rate_group_id' => null,
            'rate_code' => null,
            'rate_name' => null,
            'resolution' => 'destination_pending',
            'bosta_city_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_city_id', 'city_id']) ?: null)
                : null,
            'bosta_district_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_district_id', 'district_id']) ?: null)
                : null,
            'bosta_zone_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_zone_id', 'zone_id']) ?: null)
                : null,
            'requires_paid_shipping' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $shippingAddress
     * @return array{
     *     amount: float,
     *     currency: string,
     *     free: bool,
     *     label: string,
     *     reason: string,
     *     rate_group_id: int|null,
     *     rate_code: string|null,
     *     rate_name: array<string, string>|null,
     *     resolution: string,
     *     bosta_city_id: string|null,
     *     bosta_district_id: string|null,
     *     bosta_zone_id: string|null,
     *     requires_paid_shipping: bool
     * }
     */
    private function zeroQuote(string $reason, bool $free, ?array $shippingAddress): array
    {
        return [
            'amount' => 0.0,
            'currency' => 'EGP',
            'free' => $free,
            'label' => $free ? 'مجاني' : '',
            'reason' => $reason,
            'rate_group_id' => null,
            'rate_code' => null,
            'rate_name' => null,
            'resolution' => $reason,
            'bosta_city_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_city_id', 'city_id']) ?: null)
                : null,
            'bosta_district_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_district_id', 'district_id']) ?: null)
                : null,
            'bosta_zone_id' => $shippingAddress
                ? ($this->stringField($shippingAddress, ['bosta_zone_id', 'zone_id']) ?: null)
                : null,
            'requires_paid_shipping' => false,
        ];
    }
}
