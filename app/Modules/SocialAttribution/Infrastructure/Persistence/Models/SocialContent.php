<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ContentType;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SocialContent extends Model
{
    protected $fillable = [
        'uuid', 'campaign_id', 'platform', 'content_type', 'external_content_id',
        'title', 'external_url', 'status', 'published_at',
        'destination_type', 'destination_id', 'destination_path',
        'metadata', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialContent $content): void {
            if (empty($content->uuid)) {
                $content->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (SocialContent $content): void {
            if ($content->trackingLinks()->exists()) {
                throw new \RuntimeException('Cannot hard-delete content with tracking links. Archive it instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'platform' => AttributionPlatform::class,
            'content_type' => ContentType::class,
            'status' => CampaignStatus::class,
            'destination_type' => DestinationType::class,
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SocialCampaign::class, 'campaign_id');
    }

    public function trackingLinks(): HasMany
    {
        return $this->hasMany(TrackingLink::class, 'social_content_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
