<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'topic_id',
        'watch_progress_percent',
        'watched_seconds',
        'duration_seconds',
        'last_position_seconds',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'watch_progress_percent' => 'decimal:2',
            'watched_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'last_position_seconds' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
