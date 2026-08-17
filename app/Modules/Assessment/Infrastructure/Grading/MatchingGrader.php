<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * matching_answer expected as map: left_option_id => right_option_id
 * Correct pairing via option meta.match_key on both sides.
 */
final class MatchingGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::Matching;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || ! is_array($answer->matching_answer) || $answer->matching_answer === []) {
            return false;
        }

        $question->loadMissing('options');
        $byId = $question->options->keyBy('id');

        $lefts = $question->options->filter(fn ($o) => ($o->meta['side'] ?? null) === 'left');

        if ($lefts->isEmpty()) {
            return false;
        }

        foreach ($lefts as $left) {
            $rightId = $answer->matching_answer[$left->id]
                ?? $answer->matching_answer[(string) $left->id]
                ?? null;

            if (! $rightId || ! $byId->has((int) $rightId)) {
                return false;
            }

            $right = $byId->get((int) $rightId);
            $leftKey = (string) ($left->meta['match_key'] ?? '');
            $rightKey = (string) ($right->meta['match_key'] ?? '');

            if ($leftKey === '' || $leftKey !== $rightKey) {
                return false;
            }
        }

        return true;
    }
}
