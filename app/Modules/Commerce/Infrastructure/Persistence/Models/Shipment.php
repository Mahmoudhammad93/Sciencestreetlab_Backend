<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Persistence\Models;

use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = [
        'order_id',
        'provider',
        'external_shipment_id',
        'tracking_number',
        'tracking_url',
        'status',
        'provider_status',
        'shipped_at',
        'delivered_at',
        'last_webhook_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => ShipmentProvider::class,
            'status' => ShipmentStatus::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === ShipmentStatus::Delivered;
    }
}
