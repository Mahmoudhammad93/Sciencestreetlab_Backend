<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAttribution extends Model
{
    protected $fillable = [
        'order_id', 'order_uuid', 'visitor_key', 'attribution_session_id',
        'converting_platform', 'converting_campaign_id', 'converting_content_id',
        'converting_tracking_link_id', 'converting_code',
        'first_touch', 'last_touch', 'attribution_model', 'window_days',
        'is_attributed', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'first_touch' => 'array',
            'last_touch' => 'array',
            'is_attributed' => 'boolean',
            'captured_at' => 'datetime',
            'window_days' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attributionSession(): BelongsTo
    {
        return $this->belongsTo(AttributionSession::class, 'attribution_session_id');
    }
}
