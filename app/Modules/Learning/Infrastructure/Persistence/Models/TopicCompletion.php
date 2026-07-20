<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['enrollment_id', 'topic_id', 'watch_progress_percent', 'completed_at'];

    protected function casts(): array
    {
        return [
            'watch_progress_percent' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
