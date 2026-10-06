<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttributionConversion extends Model
{
    protected $fillable = [
        'uuid', 'order_id', 'order_uuid', 'event_name', 'event_id',
        'platform', 'campaign_id', 'content_id', 'tracking_link_id',
        'value', 'currency', 'occurred_at', 'is_attributed', 'payload_snapshot',
    ];

    protected static function booted(): void
    {
        static::creating(function (AttributionConversion $conversion): void {
            if (empty($conversion->uuid)) {
                $conversion->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'event_name' => ConversionEventName::class,
            'value' => 'decimal:2',
            'occurred_at' => 'datetime',
            'is_attributed' => 'boolean',
            'payload_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function purchaseEventId(string $orderUuid): string
    {
        return 'purchase:'.$orderUuid;
    }
}
