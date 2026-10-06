<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TrackingLinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid', 'tracking_link_id', 'attribution_session_id', 'visitor_key', 'occurred_at',
        'is_bot', 'is_unique_human', 'unique_click_key',
        'referrer_host', 'user_agent_hash', 'ip_hash',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'fbclid', 'gclid', 'ttclid', 'request_path', 'metadata', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrackingLinkClick $click): void {
            if (empty($click->uuid)) {
                $click->uuid = (string) Str::uuid();
            }
            if ($click->created_at === null) {
                $click->created_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'is_bot' => 'boolean',
            'is_unique_human' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id');
    }

    public function attributionSession(): BelongsTo
    {
        return $this->belongsTo(AttributionSession::class, 'attribution_session_id');
    }
}
