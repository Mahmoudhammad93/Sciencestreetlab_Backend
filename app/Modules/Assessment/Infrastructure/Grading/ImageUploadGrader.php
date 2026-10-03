<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * Image uploads never auto-grade — teacher must review evidence.
 */
final class ImageUploadGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::ImageUpload;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if ($answer) {
            $answer->needs_manual_review = true;
            $answer->save();
        }

        return false;
    }
}
