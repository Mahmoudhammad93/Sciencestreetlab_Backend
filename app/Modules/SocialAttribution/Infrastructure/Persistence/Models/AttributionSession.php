<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributionSession extends Model
{
    protected $fillable = [
        'visitor_key', 'cart_session_id', 'user_id',
        'window_starts_at', 'window_ends_at',
        'first_tracking_link_id', 'first_campaign_id', 'first_content_id', 'first_platform', 'first_touched_at',
        'last_tracking_link_id', 'last_campaign_id', 'last_content_id', 'last_platform', 'last_touched_at',
        'first_touch', 'last_touch', 'fbp', 'fbc', 'consent_marketing',
    ];

    protected function casts(): array
    {
        return [
            'window_starts_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'first_touched_at' => 'datetime',
            'last_touched_at' => 'datetime',
            'first_touch' => 'array',
            'last_touch' => 'array',
            'consent_marketing' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function firstTrackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'first_tracking_link_id');
    }

    public function lastTrackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'last_tracking_link_id');
    }

    public function isOpen(): bool
    {
        return $this->window_ends_at !== null && $this->window_ends_at->isFuture();
    }
}
