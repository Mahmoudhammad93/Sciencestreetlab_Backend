<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['enrollment_id', 'lesson_id', 'completed_at', 'time_spent_seconds'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
