<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuizController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $lesson = $this->resolveLesson($quiz);
        $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);

        if (! $this->access->canAccessQuiz($enrollment, $quiz)) {
            return response()->json(['message' => 'Quiz is locked.'], 403);
        }

        $quiz->load(['questions.options']);

        return response()->json([
            'data' => [
                'id' => $quiz->id,
                'title' => $quiz->getTranslation('title', app()->getLocale()),
                'instructions' => $quiz->getTranslation('instructions', app()->getLocale()),
                'passing_score' => (float) $quiz->passing_score,
                'max_attempts' => $quiz->max_attempts,
                'time_limit_seconds' => $quiz->time_limit_seconds,
                'questions' => $quiz->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'body' => $q->getTranslation('body', app()->getLocale()),
                    'question_type' => $q->question_type->value,
                    'points' => (float) $q->points,
                    'options' => $q->options->map(fn ($o) => [
                        'id' => $o->id,
                        'label' => $o->getTranslation('label', app()->getLocale()),
                    ]),
                ]),
            ],
        ]);
    }

    public function startAttempt(Request $request, Quiz $quiz): JsonResponse
    {
        $lesson = $this->resolveLesson($quiz);
        $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);

        if (! $this->access->canAccessQuiz($enrollment, $quiz)) {
            return response()->json(['message' => 'Quiz is locked.'], 403);
        }

        try {
            $attempt = $this->quizAttempts->start($request->user(), $quiz, $enrollment);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $attempt], 201);
    }

    public function submitAttempt(Request $request, QuizAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['nullable', 'array'],
            'answers.*.text_answer' => ['nullable', 'string'],
        ]);

        try {
            $graded = $this->quizAttempts->submit($attempt, $validated['answers']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $graded->loadMissing('answers');
        $correctAnswers = $graded->answers->where('is_correct', true)->count();
        $wrongAnswers = $graded->answers->where('is_correct', false)->count();
        $totalQuestions = $graded->answers->count();

        return response()->json([
            'data' => [
                'attempt_id' => $graded->id,
                'attempt_number' => $graded->attempt_number,
                'score' => (float) $graded->score,
                'max_score' => (float) $graded->max_score,
                'percentage' => (float) $graded->percentage,
                'total_questions' => $totalQuestions,
                'correct_answers' => $correctAnswers,
                'wrong_answers' => $wrongAnswers,
                'passed' => (bool) $graded->passed,
                'status' => $graded->passed ? 'passed' : 'failed',
                'submitted_at' => $graded->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    public function result(Request $request, QuizAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt->load(['answers.question.options', 'quiz']);

        return response()->json(['data' => $attempt]);
    }

    private function resolveLesson(Quiz $quiz): Lesson
    {
        $lesson = $quiz->quizable;

        if (! $lesson instanceof Lesson) {
            abort(404, 'Quiz not attached to a lesson.');
        }

        return $lesson;
    }
}
