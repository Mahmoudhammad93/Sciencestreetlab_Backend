<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Persistence\Models;

use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesChannelIntegration extends Model
{
    protected $fillable = [
        'platform',
        'name',
        'connection_status',
        'sync_status',
        'health_status',
        'external_account_id',
        'external_account_name',
        'credentials',
        'settings',
        'last_connected_at',
        'last_synced_at',
        'last_successful_sync_at',
        'last_error_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'platform' => SalesChannelPlatform::class,
            'connection_status' => ConnectionStatus::class,
            'sync_status' => SyncStatus::class,
            'health_status' => HealthStatus::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'credentials',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(SalesChannelProduct::class, 'integration_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SalesChannelActivityLog::class, 'integration_id');
    }

    public function isConnected(): bool
    {
        return $this->connection_status === ConnectionStatus::Connected;
    }
}
