<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Modules\Assessment\Domain\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Question extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['body', 'explanation'];

    protected $fillable = [
        'quiz_id', 'question_type', 'points', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'points' => 'decimal:2',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }
}
