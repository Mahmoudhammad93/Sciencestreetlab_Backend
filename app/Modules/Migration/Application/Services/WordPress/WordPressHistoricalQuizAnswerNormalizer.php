<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;

/**
 * Normalize LearnDash ProQuiz statistic answer_data into Laravel quiz_attempt_answers fields.
 *
 * Never invents missing selections. Never recomputes grades from student text —
 * preserves source points / correct_count / incorrect_count as historical evidence.
 */
final class WordPressHistoricalQuizAnswerNormalizer
{
    /**
     * @param  object{
     *     question_id?: mixed,
     *     answer_data?: mixed,
     *     points?: mixed,
     *     correct_count?: mixed,
     *     incorrect_count?: mixed
     * }  $stat
     * @return array{
     *     ok: bool,
     *     defer_reason?: string,
     *     payload?: array<string, mixed>
     * }
     */
    public function normalize(Question $question, object $stat, string $sourceAnswerType): array
    {
        $raw = isset($stat->answer_data) ? (string) $stat->answer_data : '';
        if ($raw === '') {
            return ['ok' => false, 'defer_reason' => 'EMPTY_ANSWER_DATA'];
        }

        $decoded = $this->decodeAnswerData($raw);
        if ($decoded === null) {
            return ['ok' => false, 'defer_reason' => 'MALFORMED_ANSWER_DATA'];
        }

        $question->loadMissing('options');
        $optionsBySort = $question->options->sortBy('sort_order')->values();

        $points = isset($stat->points) ? (float) $stat->points : null;
        $correctCount = (int) ($stat->correct_count ?? 0);
        $incorrectCount = (int) ($stat->incorrect_count ?? 0);
        $isCorrect = $this->resolveIsCorrect($correctCount, $incorrectCount, $points);
        $needsReview = false;

        $payload = [
            'selected_option_ids' => null,
            'text_answer' => null,
            'numeric_answer' => null,
            'matching_answer' => null,
            'ordering_answer' => null,
            'interactive_answer' => null,
            'client_result' => null,
            'server_result' => [
                'source' => 'wordpress_proquiz_statistic',
                'source_answer_type' => $sourceAnswerType,
                'correct_count' => $correctCount,
                'incorrect_count' => $incorrectCount,
                'points' => $points,
            ],
            'needs_manual_review' => false,
            'is_correct' => $isCorrect,
            'points_awarded' => $points,
        ];

        return match ($sourceAnswerType) {
            'single', 'multiple' => $this->normalizeChoice(
                $decoded,
                $optionsBySort,
                $payload,
                $sourceAnswerType === 'multiple'
            ),
            'essay' => $this->normalizeEssay($decoded, $payload, $isCorrect, $points),
            'sort_answer' => $this->normalizeOrdering($decoded, $optionsBySort, $payload),
            'matrix_sort_answer' => $this->normalizeMatching($decoded, $question, $payload),
            default => ['ok' => false, 'defer_reason' => 'UNSUPPORTED_ANSWER_TYPE'],
        };
    }

    /**
     * @return array<int|string, mixed>|string|null
     */
    private function decodeAnswerData(string $raw): array|string|null
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $json = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($json) || is_string($json) || is_numeric($json)) {
                return is_numeric($json) ? (string) $json : $json;
            }
        }

        try {
            $unser = @unserialize($trimmed, ['allowed_classes' => false]);
        } catch (\Throwable) {
            $unser = false;
        }

        if ($unser === false && $trimmed !== 'b:0;') {
            // Plain essay text fallback.
            if (! str_starts_with($trimmed, 'a:') && ! str_starts_with($trimmed, 'O:') && ! str_starts_with($trimmed, '{')) {
                return $trimmed;
            }

            return null;
        }

        if (is_string($unser) || is_array($unser)) {
            return $unser;
        }

        if (is_object($unser)) {
            return (array) $unser;
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>|string  $decoded
     * @param  \Illuminate\Support\Collection<int, QuestionOption>  $optionsBySort
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, defer_reason?: string, payload?: array<string, mixed>}
     */
    private function normalizeChoice(array|string $decoded, $optionsBySort, array $payload, bool $multiple): array
    {
        if (! is_array($decoded)) {
            return ['ok' => false, 'defer_reason' => 'CHOICE_ANSWER_NOT_VECTOR'];
        }

        $selected = [];
        $values = array_values($decoded);
        foreach ($values as $index => $flag) {
            if (! is_numeric($flag) && ! is_bool($flag)) {
                // Some sources store selected indices only (list of ints).
                if ($this->looksLikeIndexList($values)) {
                    return $this->normalizeChoiceIndexList($values, $optionsBySort, $payload, $multiple);
                }

                return ['ok' => false, 'defer_reason' => 'CHOICE_ANSWER_UNSUPPORTED_SHAPE'];
            }
            if ((int) $flag === 1 || $flag === true || $flag === '1') {
                $opt = $optionsBySort->get($index);
                if ($opt === null) {
                    return ['ok' => false, 'defer_reason' => 'CHOICE_OPTION_INDEX_MISSING'];
                }
                $selected[] = (int) $opt->id;
            }
        }

        if ($selected === [] && $this->looksLikeIndexList($values)) {
            return $this->normalizeChoiceIndexList($values, $optionsBySort, $payload, $multiple);
        }

        if (! $multiple && count($selected) > 1) {
            return ['ok' => false, 'defer_reason' => 'SINGLE_CHOICE_MULTI_SELECTED'];
        }

        $payload['selected_option_ids'] = $selected;

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * @param  list<mixed>  $indices
     * @param  \Illuminate\Support\Collection<int, QuestionOption>  $optionsBySort
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, defer_reason?: string, payload?: array<string, mixed>}
     */
    private function normalizeChoiceIndexList(array $indices, $optionsBySort, array $payload, bool $multiple): array
    {
        $selected = [];
        foreach ($indices as $idx) {
            if (! is_numeric($idx)) {
                return ['ok' => false, 'defer_reason' => 'CHOICE_INDEX_LIST_INVALID'];
            }
            $opt = $optionsBySort->get((int) $idx);
            if ($opt === null) {
                return ['ok' => false, 'defer_reason' => 'CHOICE_OPTION_INDEX_MISSING'];
            }
            $selected[] = (int) $opt->id;
        }
        if (! $multiple && count($selected) > 1) {
            return ['ok' => false, 'defer_reason' => 'SINGLE_CHOICE_MULTI_SELECTED'];
        }
        $payload['selected_option_ids'] = array_values(array_unique($selected));

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function looksLikeIndexList(array $values): bool
    {
        if ($values === []) {
            return false;
        }
        foreach ($values as $v) {
            if (! is_numeric($v)) {
                return false;
            }
            // Binary flag vectors contain only 0/1; index lists often contain higher indices.
            if ((int) $v > 1) {
                return true;
            }
        }

        // All 0/1 — treat as flag vector, not index list.
        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $decoded
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, defer_reason?: string, payload?: array<string, mixed>}
     */
    private function normalizeEssay(array|string $decoded, array $payload, ?bool $isCorrect, ?float $points): array
    {
        $text = $this->extractEssayText($decoded);
        if ($text === null) {
            return ['ok' => false, 'defer_reason' => 'ESSAY_TEXT_UNRECOVERABLE'];
        }

        $payload['text_answer'] = $text;
        // Historical essays previously showed grading evidence — preserve, do not reopen review.
        $payload['needs_manual_review'] = false;
        if ($isCorrect === null && ($points === null || $points <= 0.0)) {
            // No grading evidence → leave correctness null; still not a live grading job.
            $payload['is_correct'] = null;
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * @param  array<int|string, mixed>|string  $decoded
     */
    private function extractEssayText(array|string $decoded): ?string
    {
        if (is_string($decoded)) {
            return $decoded;
        }

        if (isset($decoded['graded_id']) && is_numeric($decoded['graded_id'])) {
            // Text may live on a WP graded post; preserve id evidence without inventing body.
            $payload = json_encode($decoded);
            return $payload === false ? null : $payload;
        }

        foreach ($decoded as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value)) {
                foreach ($value as $inner) {
                    if (is_string($inner) && $inner !== '') {
                        return $inner;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>|string  $decoded
     * @param  \Illuminate\Support\Collection<int, QuestionOption>  $optionsBySort
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, defer_reason?: string, payload?: array<string, mixed>}
     */
    private function normalizeOrdering(array|string $decoded, $optionsBySort, array $payload): array
    {
        if (! is_array($decoded)) {
            return ['ok' => false, 'defer_reason' => 'ORDERING_NOT_ARRAY'];
        }

        $order = [];
        foreach (array_values($decoded) as $idx) {
            if (! is_numeric($idx)) {
                return ['ok' => false, 'defer_reason' => 'ORDERING_INDEX_INVALID'];
            }
            $opt = $optionsBySort->get((int) $idx);
            if ($opt === null) {
                return ['ok' => false, 'defer_reason' => 'ORDERING_OPTION_INDEX_MISSING'];
            }
            $order[] = (int) $opt->id;
        }

        if ($order === []) {
            return ['ok' => false, 'defer_reason' => 'ORDERING_EMPTY'];
        }

        $payload['ordering_answer'] = $order;

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * Matrix sort → Matching: criterion index → answer index mapped to left/right option ids.
     *
     * @param  array<int|string, mixed>|string  $decoded
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, defer_reason?: string, payload?: array<string, mixed>}
     */
    private function normalizeMatching(array|string $decoded, Question $question, array $payload): array
    {
        if (! is_array($decoded)) {
            return ['ok' => false, 'defer_reason' => 'MATCHING_NOT_ARRAY'];
        }

        $leftByIndex = [];
        $rightByIndex = [];
        foreach ($question->options as $opt) {
            $meta = is_array($opt->meta) ? $opt->meta : [];
            if (($meta['side'] ?? null) === 'left' && isset($meta['match_key'])) {
                if (preg_match('/^m(\d+)$/', (string) $meta['match_key'], $m) === 1) {
                    $leftByIndex[(int) $m[1]] = (int) $opt->id;
                }
            }
            if (($meta['side'] ?? null) === 'right' && isset($meta['match_key'])) {
                if (preg_match('/^m(\d+)$/', (string) $meta['match_key'], $m) === 1) {
                    $rightByIndex[(int) $m[1]] = (int) $opt->id;
                }
            }
        }

        if ($leftByIndex === [] || $rightByIndex === []) {
            return ['ok' => false, 'defer_reason' => 'MATCHING_OPTIONS_MISSING_META'];
        }

        $matches = [];
        foreach ($decoded as $criterionIndex => $answerIndex) {
            if (! is_numeric($criterionIndex) || ! is_numeric($answerIndex)) {
                return ['ok' => false, 'defer_reason' => 'MATCHING_PAIR_INVALID'];
            }
            $cIdx = (int) $criterionIndex;
            $aIdx = (int) $answerIndex;
            if (! isset($leftByIndex[$cIdx], $rightByIndex[$aIdx])) {
                return ['ok' => false, 'defer_reason' => 'MATCHING_INDEX_OUT_OF_RANGE'];
            }
            $matches[(string) $leftByIndex[$cIdx]] = $rightByIndex[$aIdx];
        }

        if ($matches === []) {
            return ['ok' => false, 'defer_reason' => 'MATCHING_EMPTY'];
        }

        $payload['matching_answer'] = $matches;

        return ['ok' => true, 'payload' => $payload];
    }

    private function resolveIsCorrect(int $correctCount, int $incorrectCount, ?float $points): ?bool
    {
        if ($correctCount > 0 && $incorrectCount === 0) {
            return true;
        }
        if ($incorrectCount > 0 && $correctCount === 0) {
            return false;
        }
        if ($points !== null && $points > 0.0 && $incorrectCount === 0) {
            return true;
        }
        if ($points !== null && $points <= 0.0 && $correctCount === 0 && $incorrectCount > 0) {
            return false;
        }

        return null;
    }

    public function laravelTypeForSource(string $sourceAnswerType): ?QuestionType
    {
        return match ($sourceAnswerType) {
            'single' => QuestionType::SingleChoice,
            'multiple' => QuestionType::MultipleChoice,
            'essay' => QuestionType::LongAnswer,
            'sort_answer' => QuestionType::Ordering,
            'matrix_sort_answer' => QuestionType::Matching,
            default => null,
        };
    }
}
