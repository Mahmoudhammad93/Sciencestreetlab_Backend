<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'amount', 'source_type', 'source_id', 'description', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
