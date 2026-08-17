<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuestionBank */
final class QuestionBankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var QuestionBank $bank */
        $bank = $this->resource;
        $locale = app()->getLocale();
        $lesson = $bank->relationLoaded('lesson') ? $bank->lesson : $bank->lesson()->first();

        $quizzes = $bank->relationLoaded('quizzes')
            ? $bank->quizzes
            : $bank->quizzes()->get(['quizzes.id', 'uuid', 'title', 'selection_mode', 'passing_score', 'is_required']);

        return [
            'id' => $bank->id,
            'uuid' => $bank->uuid,
            'title' => $bank->getTranslation('title', $locale),
            'description' => $bank->getTranslation('description', $locale) ?: null,
            'status' => $bank->status?->value,
            'question_count' => $bank->published_questions_count
                ?? $bank->questions_count
                ?? $bank->questions()->where('status', 'published')->count(),
            'lesson' => $lesson ? [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->getTranslation('title', $locale),
                'course_id' => $lesson->course_id,
            ] : null,
            'available_quizzes' => $quizzes->map(fn ($quiz) => [
                'id' => $quiz->id,
                'uuid' => $quiz->uuid,
                'title' => $quiz->getTranslation('title', $locale),
                'selection_mode' => $quiz->selection_mode?->value ?? 'fixed',
                'passing_score' => (float) $quiz->passing_score,
                'is_required' => (bool) $quiz->is_required,
            ])->values(),
        ];
    }
}
