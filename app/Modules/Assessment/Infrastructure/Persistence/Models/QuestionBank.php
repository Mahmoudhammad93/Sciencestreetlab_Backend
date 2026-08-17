<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class QuestionBank extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'description'];

    protected $fillable = [
        'uuid', 'lesson_id', 'status', 'created_by', 'title', 'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (QuestionBank $bank): void {
            if (empty($bank->uuid)) {
                $bank->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => QuestionBankStatus::class,
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_question_bank');
    }
}
