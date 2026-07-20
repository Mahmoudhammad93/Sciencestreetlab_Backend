<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = [
        'user_id', 'device_id', 'fcm_token', 'platform', 'app_version', 'last_active_at',
    ];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
