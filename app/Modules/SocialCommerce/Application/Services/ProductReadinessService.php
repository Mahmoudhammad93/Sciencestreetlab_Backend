<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;

final class ProductReadinessService
{
    public function __construct(
        private readonly ProductChannelMapper $mapper,
    ) {}

    /**
     * @return list<array{code: string, label: string, ok: bool}>
     */
    public function checklist(Product $product): array
    {
        $data = $this->mapper->map($product);
        $hasTitle = filled($product->getTranslation('name', 'en'))
            || filled($product->getTranslation('name', 'ar'));

        return [
            $this->item('title', $hasTitle, 'sales_channels.readiness.title'),
            $this->item('price', $this->hasValidPrice($data), 'sales_channels.readiness.price'),
            $this->item('url', filled($data->url), 'sales_channels.readiness.url'),
            $this->item('main_image', filled($data->mainImage), 'sales_channels.readiness.main_image'),
            $this->item('availability', filled($data->availability), 'sales_channels.readiness.availability'),
            $this->item('brand', filled($data->brand), 'sales_channels.readiness.brand', required: false),
        ];
    }

    /**
     * @return list<string>
     */
    public function blockingIssueCodes(Product $product): array
    {
        return collect($this->checklist($product))
            ->filter(fn (array $item) => ($item['required'] ?? true) && ! $item['ok'])
            ->pluck('code')
            ->values()
            ->all();
    }

    public function isReady(Product $product): bool
    {
        return $this->blockingIssueCodes($product) === [];
    }

    /**
     * @return array{code: string, label: string, ok: bool, required: bool}
     */
    private function item(string $code, bool $ok, string $labelKey, bool $required = true): array
    {
        return [
            'code' => $code,
            'label' => (string) __($labelKey),
            'ok' => $ok,
            'required' => $required,
        ];
    }

    private function hasValidPrice(SalesChannelProductData $data): bool
    {
        return is_numeric($data->price) && (float) $data->price > 0 && filled($data->currency);
    }
}
