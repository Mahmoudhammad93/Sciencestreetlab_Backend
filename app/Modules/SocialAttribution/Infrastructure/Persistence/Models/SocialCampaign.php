<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ChannelKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SocialCampaign extends Model
{
    protected $fillable = [
        'uuid', 'platform', 'name', 'code', 'channel_kind', 'external_campaign_id',
        'status', 'starts_at', 'ends_at', 'metadata', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialCampaign $campaign): void {
            if (empty($campaign->uuid)) {
                $campaign->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (SocialCampaign $campaign): void {
            if ($campaign->trackingLinks()->exists() || $campaign->contents()->exists()) {
                throw new \RuntimeException('Cannot hard-delete a campaign with linked content or tracking links. Archive it instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'platform' => AttributionPlatform::class,
            'channel_kind' => ChannelKind::class,
            'status' => CampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(SocialContent::class, 'campaign_id');
    }

    public function trackingLinks(): HasMany
    {
        return $this->hasMany(TrackingLink::class, 'campaign_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', CampaignStatus::Active->value);
    }
}
