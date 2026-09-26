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
            'platform' => $integration->platform->value,
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

<style>
    .psc-list { display: grid; gap: 0.65rem; }
    .psc-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        border: 1px solid rgba(148,163,184,.25);
        border-radius: .9rem;
        padding: .85rem 1rem;
        background: rgba(248,250,252,.7);
    }
    .dark .psc-row { background: rgba(15,23,42,.35); border-color: rgba(148,163,184,.18); }
    .psc-name { font-weight: 700; font-size: .925rem; }
    .psc-status {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: .8rem;
        font-weight: 650;
        white-space: nowrap;
    }
    .psc-status.is-ok { color: #047857; }
    .psc-status.is-warn { color: #b45309; }
    .psc-status.is-idle { color: #64748b; }
    .psc-msg { margin-top: .35rem; font-size: .8rem; color: #b45309; }
</style>

<div class="psc-list" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <div class="psc-row">
        <div class="psc-name">{{ __('sales_channels.website') }}</div>
        <div class="psc-status {{ $websitePublished ? 'is-ok' : 'is-idle' }}">
            <span aria-hidden="true">{{ $websitePublished ? '✓' : '○' }}</span>
            {{ $websitePublished ? __('sales_channels.product_website_published') : __('sales_channels.product_not_listed') }}
        </div>
    </div>

    @foreach ($channelRows as $row)
        <div class="psc-row">
            <div>
                <div class="psc-name">{{ $row['name'] }}</div>
                @if ($row['issue'])
                    <div class="psc-msg">{{ $row['issue']['message'] }}</div>
                @endif
            </div>
            <div class="psc-status
                @if ($row['status'] === PublicationStatus::Published) is-ok
                @elseif ($row['status'] === PublicationStatus::NeedsAttention) is-warn
                @else is-idle
                @endif">
                <span aria-hidden="true">
                    @if ($row['status'] === PublicationStatus::Published) ✓
                    @elseif ($row['status'] === PublicationStatus::NeedsAttention) ⚠
                    @else ○
                    @endif
                </span>
                {{ $row['label'] }}
            </div>
        </div>
    @endforeach
</div>
