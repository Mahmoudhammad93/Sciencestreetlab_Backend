<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;

final class ProductChannelMapper
{
    public function map(Product $product, string $locale = 'en'): SalesChannelProductData
    {
        $product->loadMissing(['category', 'media']);

        $frontend = rtrim((string) config('sciencestreet.frontend_url', ''), '/');
        $url = $frontend !== ''
            ? $frontend.'/shop/'.$product->slug
            : '/shop/'.$product->slug;

        $title = $product->getTranslation('name', $locale)
            ?: $product->getTranslation('name', 'en')
            ?: $product->getTranslation('name', 'ar')
            ?: $product->sku;

        $description = $product->getTranslation('description', $locale)
            ?: $product->getTranslation('short_description', $locale)
            ?: $product->getTranslation('description', 'en')
            ?: $product->getTranslation('short_description', 'en')
            ?: '';

        $inStock = ! $product->manage_stock || (int) $product->stock_quantity > 0;

        $brand = (string) config('sciencestreet.name', 'Science Street Lab');

        return new SalesChannelProductData(
            sku: (string) $product->sku,
            title: is_string($title) ? $title : (string) $product->sku,
            description: is_string($description) ? strip_tags($description) : '',
            price: (string) $product->price,
            currency: (string) ($product->currency ?: 'EGP'),
            availability: $inStock ? 'in_stock' : 'out_of_stock',
            url: $url,
            mainImage: $product->image,
            additionalImages: array_values(array_filter(
                $product->gallery_urls,
                fn ($image) => is_string($image) && $image !== '' && $image !== $product->image
            )),
            brand: $brand !== '' ? $brand : null,
            category: $product->category?->getTranslation('name', $locale)
                ?: $product->category?->getTranslation('name', 'en')
                ?: $product->category?->slug,
        );
    }
}
