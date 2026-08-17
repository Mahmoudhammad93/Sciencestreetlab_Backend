<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class QuestionOption extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = [
        'question_id', 'is_correct', 'sort_order', 'label', 'meta',
    ];

    protected $hidden = [
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
