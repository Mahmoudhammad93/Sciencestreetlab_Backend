@php
    use App\Modules\Catalog\Domain\Enums\ProductStatus;
    use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
    use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
    use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
    use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
    use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelProduct;

    /** @var \App\Modules\Catalog\Infrastructure\Persistence\Models\Product|null $product */
    $product = $getRecord();
    $errors = app(HumanErrorMapper::class);

    if ($product) {
        app(SalesChannelManager::class)->ensureDefaults();
    }

    $integrations = SalesChannelIntegration::query()->orderBy('id')->get();
    $channelRows = $integrations->map(function (SalesChannelIntegration $integration) use ($product, $errors): array {
        $channelProduct = $product
            ? SalesChannelProduct::query()
                ->where('product_id', $product->id)
                ->where('integration_id', $integration->id)
                ->first()
            : null;

        $status = $channelProduct?->publication_status ?? PublicationStatus::NotPublished;
        $issue = null;
        if ($status === PublicationStatus::NeedsAttention) {
            $issue = $errors->present($channelProduct?->last_error_code);
        }

        return [
            'name' => $integration->platform->label(),
            'status' => $status,
            'label' => $status->label(),
            'issue' => $issue,
        ];
    });

    $websitePublished = $product
        && $product->status === ProductStatus::Published
        && $product->published_at !== null;
@endphp

<div class="space-y-3" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
        <span class="font-medium text-gray-900 dark:text-white">{{ __('sales_channels.website') }}</span>
        <span class="inline-flex items-center gap-1.5 text-sm
            {{ $websitePublished ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500' }}">
            <span aria-hidden="true">{{ $websitePublished ? '✓' : '○' }}</span>
            {{ $websitePublished ? __('sales_channels.product_website_published') : __('sales_channels.product_not_listed') }}
        </span>
    </div>

    @foreach ($channelRows as $row)
        <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center justify-between gap-3">
                <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                <span class="inline-flex items-center gap-1.5 text-sm
                    @if ($row['status'] === PublicationStatus::Published) text-emerald-700 dark:text-emerald-300
                    @elseif ($row['status'] === PublicationStatus::NeedsAttention) text-amber-800 dark:text-amber-200
                    @else text-gray-500
                    @endif">
                    <span aria-hidden="true">
                        @if ($row['status'] === PublicationStatus::Published) ✓
                        @elseif ($row['status'] === PublicationStatus::NeedsAttention) ⚠
                        @else ○
                        @endif
                    </span>
                    {{ $row['label'] }}
                </span>
            </div>
            @if ($row['issue'])
                <p class="mt-1 text-sm text-amber-900 dark:text-amber-100">{{ $row['issue']['message'] }}</p>
            @endif
        </div>
    @endforeach
</div>
