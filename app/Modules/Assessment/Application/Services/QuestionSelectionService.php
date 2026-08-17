<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class QuestionSelectionService
{
    /**
     * @param  array{
     *     total_questions?: int,
     *     difficulty?: array{easy?: int, medium?: int, hard?: int},
     *     question_types?: list<string>,
     *     tag_slugs?: list<string>,
     *     exclude_question_ids?: list<int>
     * }|null  $config
     * @return Collection<int, Question>
     */
    public function selectForQuiz(Quiz $quiz, ?array $config = null): Collection
    {
        $config ??= $quiz->selection_config ?? [];
        $bankIds = $quiz->questionBanks()->pluck('question_banks.id');

        if ($bankIds->isEmpty()) {
            throw new DomainException('INSUFFICIENT_QUESTIONS: Not enough questions available.', 409);
        }

        $exclude = collect($config['exclude_question_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $types = $config['question_types'] ?? null;
        $tagSlugs = $config['tag_slugs'] ?? null;
        $difficultyPlan = $config['difficulty'] ?? null;
        $total = (int) ($config['total_questions'] ?? 0);

        if (is_array($difficultyPlan) && $difficultyPlan !== []) {
            return $this->selectByDifficultyPlan($bankIds->all(), $difficultyPlan, $types, $tagSlugs, $exclude);
        }

        if ($total < 1) {
            throw new DomainException('INSUFFICIENT_QUESTIONS: Not enough questions available.', 409);
        }

        $query = $this->baseQuery($bankIds->all(), $types, $tagSlugs, $exclude);
        $ids = (clone $query)->inRandomOrder()->limit($total)->pluck('id');

        if ($ids->count() < $total) {
            throw new DomainException(
                "INSUFFICIENT_QUESTIONS: Not enough questions available.",
                409
            );
        }

        return Question::query()
            ->with('options')
            ->whereIn('id', $ids)
            ->get()
            ->shuffle()
            ->values();
    }

    /**
     * @param  list<int>  $bankIds
     * @param  array{easy?: int, medium?: int, hard?: int}  $plan
     * @param  list<string>|null  $types
     * @param  list<string>|null  $tagSlugs
     * @param  list<int>  $exclude
     * @return Collection<int, Question>
     */
    private function selectByDifficultyPlan(
        array $bankIds,
        array $plan,
        ?array $types,
        ?array $tagSlugs,
        array $exclude,
    ): Collection {
        $selected = collect();

        foreach ([
            QuestionDifficulty::Easy->value => (int) ($plan['easy'] ?? 0),
            QuestionDifficulty::Medium->value => (int) ($plan['medium'] ?? 0),
            QuestionDifficulty::Hard->value => (int) ($plan['hard'] ?? 0),
        ] as $difficulty => $count) {
            if ($count < 1) {
                continue;
            }

            $query = $this->baseQuery($bankIds, $types, $tagSlugs, array_merge($exclude, $selected->all()));
            $ids = (clone $query)
                ->where('difficulty', $difficulty)
                ->inRandomOrder()
                ->limit($count)
                ->pluck('id');

            if ($ids->count() < $count) {
                throw new DomainException(
                    'INSUFFICIENT_QUESTIONS: Not enough questions available.',
                    409
                );
            }

            $selected = $selected->merge($ids);
        }

        if ($selected->isEmpty()) {
            throw new DomainException('INSUFFICIENT_QUESTIONS: Not enough questions available.', 409);
        }

        return Question::query()
            ->with('options')
            ->whereIn('id', $selected->all())
            ->get()
            ->shuffle()
            ->values();
    }

    /**
     * @param  list<int>  $bankIds
     * @param  list<string>|null  $types
     * @param  list<string>|null  $tagSlugs
     * @param  list<int>  $exclude
     */
    private function baseQuery(array $bankIds, ?array $types, ?array $tagSlugs, array $exclude)
    {
        $query = Question::query()
            ->whereIn('question_bank_id', $bankIds)
            ->where('status', QuestionStatus::Published->value);

        if ($exclude !== []) {
            $query->whereNotIn('id', $exclude);
        }

        if (is_array($types) && $types !== []) {
            $query->whereIn('question_type', $types);
        }

        if (is_array($tagSlugs) && $tagSlugs !== []) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('slug', $tagSlugs));
        }

        return $query;
    }
}
