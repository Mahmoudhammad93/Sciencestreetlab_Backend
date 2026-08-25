<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Support;

use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * Builds student-facing review payloads after a quiz is submitted.
 * Correct answers are included only on result/review endpoints.
 */
final class QuizReviewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function userAnswer(QuizAttemptAnswer $answer, ?Question $question = null): array
    {
        $payload = [];
        $ids = is_array($answer->selected_option_ids) ? array_map('intval', $answer->selected_option_ids) : [];
        $locale = app()->getLocale();

        if (count($ids) === 1) {
            $payload['option_id'] = $ids[0];
        }
        if ($ids !== []) {
            $payload['option_ids'] = $ids;
        }
        if ($answer->text_answer !== null && $answer->text_answer !== '') {
            $payload['text'] = $answer->text_answer;
        }
        if ($answer->numeric_answer !== null) {
            $payload['numeric'] = (float) $answer->numeric_answer;
        }
        if (is_array($answer->matching_answer)) {
            $payload['matches'] = $answer->matching_answer;
        }
        if (is_array($answer->ordering_answer)) {
            $payload['order'] = array_map('intval', $answer->ordering_answer);
        }
        if (is_array($answer->interactive_answer)) {
            $payload['interactive'] = $answer->interactive_answer;
        }
        if (is_array($answer->client_result)) {
            $payload['client_result'] = $answer->client_result;
        }

        if ($question && $ids !== []) {
            $question->loadMissing('options');
            $payload['labels'] = $question->options
                ->whereIn('id', $ids)
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'label' => $o->getTranslation('label', $locale),
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function correctAnswer(?Question $question): ?array
    {
        if (! $question) {
            return null;
        }

        $question->loadMissing('options');
        $locale = app()->getLocale();
        $key = is_array($question->answer_key) ? $question->answer_key : [];

        return match ($question->question_type) {
            QuestionType::SingleChoice, QuestionType::TrueFalse => $this->correctOptions($question, $locale, true),
            QuestionType::MultipleChoice => $this->correctOptions($question, $locale, false),
            QuestionType::ShortAnswer, QuestionType::LongAnswer => [
                'text' => $this->acceptedTexts($key)[0] ?? $question->getTranslation('explanation', $locale) ?: null,
                'accepted' => $this->acceptedTexts($key),
            ],
            QuestionType::FillBlank => [
                'blanks' => $key['blanks'] ?? null,
                'accepted' => $this->acceptedTexts($key),
            ],
            QuestionType::Numeric => [
                'value' => $key['value'] ?? null,
                'tolerance' => $key['tolerance'] ?? 0,
            ],
            QuestionType::Matching => [
                'matches' => $this->correctMatches($question),
            ],
            QuestionType::Ordering => [
                'order' => $question->options->sortBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'labels' => $question->options->sortBy('sort_order')->map(fn ($o) => [
                    'id' => $o->id,
                    'label' => $o->getTranslation('label', $locale),
                ])->values()->all(),
            ],
            QuestionType::InteractiveHtml, QuestionType::InteractiveActivity => [
                'expected' => $key['expected'] ?? null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function correctOptions(Question $question, string $locale, bool $single): array
    {
        $correct = $question->options->filter(fn ($o) => (bool) $o->is_correct)->values();
        $ids = $correct->pluck('id')->map(fn ($id) => (int) $id)->all();
        $labels = $correct->map(fn ($o) => [
            'id' => $o->id,
            'label' => $o->getTranslation('label', $locale),
        ])->all();

        $payload = [
            'option_ids' => $ids,
            'labels' => $labels,
        ];

        if ($single && count($ids) === 1) {
            $payload['option_id'] = $ids[0];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $key
     * @return list<string>
     */
    private function acceptedTexts(array $key): array
    {
        $accepted = $key['accepted'] ?? $key['text'] ?? [];

        if (is_string($accepted)) {
            return [$accepted];
        }

        if (! is_array($accepted)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $accepted)));
    }

    /**
     * @return array<int, int>
     */
    private function correctMatches(Question $question): array
    {
        $byKey = [];
        foreach ($question->options as $option) {
            $meta = is_array($option->meta) ? $option->meta : [];
            $side = $meta['side'] ?? null;
            $matchKey = (string) ($meta['match_key'] ?? '');
            if ($matchKey === '' || $side === null) {
                continue;
            }
            $byKey[$matchKey][$side] = (int) $option->id;
        }

        $matches = [];
        foreach ($byKey as $pair) {
            if (isset($pair['left'], $pair['right'])) {
                $matches[$pair['left']] = $pair['right'];
            }
        }

        return $matches;
    }
}
