<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * Interactive HTML grader.
 * Never trusts clientScore. Grades from answer_key.expected when present;
 * otherwise stores client payload as untrusted and marks needs_manual_review.
 */
final class InteractiveHtmlGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::InteractiveHtml
            || $type === QuestionType::InteractiveActivity;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer) {
            return false;
        }

        $client = $answer->client_result ?? [];
        $interactive = $answer->interactive_answer ?? [];

        $answer->server_result = [
            'trusted' => false,
            'client_score' => $client['clientScore'] ?? $client['score'] ?? null,
            'verified_at' => now()->toIso8601String(),
        ];

        $expected = $question->answer_key['expected'] ?? null;

        if ($expected === null) {
            $answer->needs_manual_review = true;
            $answer->server_result = array_merge($answer->server_result, [
                'reason' => 'no_server_answer_key',
            ]);
            $answer->save();

            return false;
        }

        $payload = $interactive['answer'] ?? $interactive['result'] ?? $interactive;
        $isCorrect = $this->deepEqual($expected, $payload);

        $answer->server_result = array_merge($answer->server_result, [
            'trusted' => true,
            'is_correct' => $isCorrect,
        ]);
        $answer->save();

        return $isCorrect;
    }

    private function deepEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b) && array_is_list($a) && array_is_list($b)) {
                // compare list values regardless of string/int keys
            }

            ksort($a);
            ksort($b);

            if (array_keys($a) !== array_keys($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! $this->deepEqual($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0001;
        }

        return $a === $b;
    }
}
