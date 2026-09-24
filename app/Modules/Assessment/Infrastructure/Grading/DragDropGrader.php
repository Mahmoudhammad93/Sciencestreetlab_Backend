<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * Student matching_answer: map of item_key => zone_key.
 * Correct mappings stored server-side in answer_key.correct_mappings (never exposed to students).
 */
final class DragDropGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::DragDrop;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || ! is_array($answer->matching_answer) || $answer->matching_answer === []) {
            return false;
        }

        $config = is_array($question->answer_key) ? $question->answer_key : [];
        $correct = $config['correct_mappings'] ?? $config['answer_key'] ?? [];
        if (! is_array($correct) || $correct === []) {
            return false;
        }

        $items = $this->keysFromList($config['items'] ?? []);
        $zones = $this->keysFromList($config['zones'] ?? $config['drop_zones'] ?? []);

        $given = [];
        foreach ($answer->matching_answer as $itemKey => $zoneKey) {
            $item = (string) $itemKey;
            $zone = is_scalar($zoneKey) ? (string) $zoneKey : '';

            if ($item === '' || $zone === '') {
                return false;
            }
            if ($items !== [] && ! in_array($item, $items, true)) {
                return false;
            }
            if ($zones !== [] && ! in_array($zone, $zones, true)) {
                return false;
            }
            if (array_key_exists($item, $given)) {
                return false; // duplicate item mapping
            }
            $given[$item] = $zone;
        }

        foreach ($correct as $itemKey => $zoneKey) {
            $item = (string) $itemKey;
            $expectedZone = (string) $zoneKey;
            if (! array_key_exists($item, $given) || $given[$item] !== $expectedZone) {
                return false;
            }
        }

        // Extra mappings beyond the answer key fail (strict)
        return count($given) === count($correct);
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function keysFromList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $keys = [];
        foreach ($list as $row) {
            if (is_array($row) && isset($row['key'])) {
                $keys[] = (string) $row['key'];
            }
        }

        return $keys;
    }
}
