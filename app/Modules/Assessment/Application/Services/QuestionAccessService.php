<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;

final class QuestionAccessService
{
    public function __construct(
        private readonly CourseAccessService $access,
    ) {}

    public function authorizeBank(User $user, QuestionBank $bank): Lesson
    {
        if ($bank->status !== QuestionBankStatus::Active) {
            throw new DomainException('QUESTION_BANK_NOT_FOUND: Question bank not found.', 404);
        }

        $lesson = $bank->lesson;
        if (! $lesson || ! $lesson->is_published) {
            throw new DomainException('QUESTION_BANK_NOT_FOUND: Question bank not found.', 404);
        }

        $this->authorizeLesson($user, $lesson);

        return $lesson;
    }

    public function authorizeLesson(User $user, Lesson $lesson): void
    {
        try {
            $enrollment = $this->access->requireEnrollment($user, $lesson->course);
        } catch (DomainException) {
            throw new DomainException('QUESTION_ACCESS_DENIED: You are not allowed to access this question.', 403);
        }

        if (! $this->access->canAccessLesson($enrollment, $lesson)) {
            throw new DomainException('QUESTION_ACCESS_DENIED: You are not allowed to access this question.', 403);
        }
    }

    public function authorizeQuestion(User $user, Question $question): void
    {
        if ($question->status !== QuestionStatus::Published) {
            throw new DomainException('QUESTION_NOT_FOUND: Question not found.', 404);
        }

        if ($question->question_bank_id) {
            $bank = $question->bank ?? QuestionBank::query()->find($question->question_bank_id);
            if ($bank) {
                $this->authorizeBank($user, $bank);

                return;
            }
        }

        if ($question->quiz_id) {
            $quiz = $question->quiz ?? Quiz::query()->find($question->quiz_id);
            if ($quiz && $quiz->quizable instanceof Lesson) {
                $this->authorizeLesson($user, $quiz->quizable);

                return;
            }
        }

        throw new DomainException('QUESTION_ACCESS_DENIED: You are not allowed to access this question.', 403);
    }

    public function authorizeAttempt(User $user, QuizAttempt $attempt): void
    {
        if ($attempt->user_id !== $user->id) {
            throw new DomainException('QUESTION_ACCESS_DENIED: You are not allowed to access this attempt.', 403);
        }
    }

    public function assertQuestionOnAttempt(QuizAttempt $attempt, Question $question, QuizAttemptService $attempts): void
    {
        $ids = $attempts->questionsForAttempt($attempt)->pluck('id');
        if (! $ids->contains($question->id)) {
            throw new DomainException('QUESTION_NOT_FOUND: Question is not part of this attempt.', 404);
        }
    }
}
