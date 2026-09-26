<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesChannelProduct extends Model
{
    protected $fillable = [
        'product_id',
        'integration_id',
        'external_product_id',
        'publication_status',
        'sync_status',
        'last_synced_at',
        'last_error_code',
        'last_error_message',
        'readiness_issues',
    ];

    protected function casts(): array
    {
        return [
            'publication_status' => PublicationStatus::class,
            'sync_status' => SyncStatus::class,
            'last_synced_at' => 'datetime',
            'readiness_issues' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(SalesChannelIntegration::class, 'integration_id');
    }
}
