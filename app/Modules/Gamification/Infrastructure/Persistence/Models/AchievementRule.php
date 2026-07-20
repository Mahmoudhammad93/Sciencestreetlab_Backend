<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AchievementRule extends Model
{
    protected $fillable = ['achievement_id', 'trigger_event', 'conditions'];

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }
}
