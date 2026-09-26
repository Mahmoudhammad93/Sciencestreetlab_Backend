<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Data;

final class SalesChannelProductData
{
    /**
     * @param  list<string>  $additionalImages
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $title,
        public readonly string $description,
        public readonly string $price,
        public readonly string $currency,
        public readonly string $availability,
        public readonly string $url,
        public readonly ?string $mainImage,
        public readonly array $additionalImages,
        public readonly ?string $brand,
        public readonly ?string $category,
        public readonly string $condition = 'new',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'availability' => $this->availability,
            'url' => $this->url,
            'main_image' => $this->mainImage,
            'additional_images' => $this->additionalImages,
            'brand' => $this->brand,
            'category' => $this->category,
            'condition' => $this->condition,
        ];
    }
}
