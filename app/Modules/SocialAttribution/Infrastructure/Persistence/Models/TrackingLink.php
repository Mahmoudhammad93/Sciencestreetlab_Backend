<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrackingLink extends Model
{
    protected $fillable = [
        'uuid', 'code', 'platform', 'campaign_id', 'social_content_id', 'label',
        'destination_type', 'destination_id', 'destination_path',
        'is_enabled', 'status', 'expires_at',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrackingLink $link): void {
            if (empty($link->uuid)) {
                $link->uuid = (string) Str::uuid();
            }
            if (is_string($link->code)) {
                $link->code = strtolower(trim($link->code));
            }
        });

        static::deleting(function (TrackingLink $link): void {
            if ($link->clicks()->exists()) {
                throw new \RuntimeException('Cannot hard-delete a tracking link with clicks. Archive it instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'platform' => AttributionPlatform::class,
            'destination_type' => DestinationType::class,
            'status' => CampaignStatus::class,
            'is_enabled' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SocialCampaign::class, 'campaign_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(SocialContent::class, 'social_content_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(TrackingLinkClick::class, 'tracking_link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRunnable(): bool
    {
        if (! $this->is_enabled || $this->status !== CampaignStatus::Active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
