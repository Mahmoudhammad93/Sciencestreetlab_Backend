<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Http\Resources\StudentQuestionResource;
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
            return response()->json(['message' => 'Quiz is locked.', 'code' => 'QUESTION_LOCKED'], 403);
        }

        $locale = app()->getLocale();

        return response()->json([
            'data' => [
                'id' => $quiz->id,
                'uuid' => $quiz->uuid,
                'title' => $quiz->getTranslation('title', $locale),
                'instructions' => $quiz->getTranslation('instructions', $locale),
                'passing_score' => (float) $quiz->passing_score,
                'max_attempts' => $quiz->max_attempts,
                'time_limit_seconds' => $quiz->time_limit_seconds,
                'selection_mode' => $quiz->selection_mode?->value ?? 'fixed',
                'shuffle_questions' => (bool) $quiz->shuffle_questions,
                'is_required' => (bool) $quiz->is_required,
                // Fixed quizzes may preview question count; generated hides actual set until attempt
                'question_count' => $quiz->isGenerated()
                    ? (int) ($quiz->selection_config['total_questions']
                        ?? array_sum($quiz->selection_config['difficulty'] ?? []))
                    : $quiz->questions()->count(),
            ],
        ]);
    }

    public function startAttempt(Request $request, Quiz $quiz): JsonResponse
    {
        $lesson = $this->resolveLesson($quiz);
        $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);

        if (! $this->access->canAccessQuiz($enrollment, $quiz)) {
            return response()->json(['message' => 'Quiz is locked.', 'code' => 'QUESTION_LOCKED'], 403);
        }

        try {
            $attempt = $this->quizAttempts->start($request->user(), $quiz, $enrollment);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return response()->json(['message' => $e->getMessage(), 'code' => $this->codeFromMessage($e->getMessage())], $status);
        }

        $questions = $this->quizAttempts->questionsForAttempt($attempt);

        return response()->json([
            'data' => [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'user_id' => $attempt->user_id,
                'enrollment_id' => $attempt->enrollment_id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'questions' => $questions->map(
                    fn ($q) => (new StudentQuestionResource($q))->toArray($request)
                )->values(),
            ],
        ], 201);
    }

    public function showAttempt(Request $request, QuizAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden', 'code' => 'FORBIDDEN'], 403);
        }

        $questions = $this->quizAttempts->questionsForAttempt($attempt);

        return response()->json([
            'data' => [
                'attempt' => [
                    'id' => $attempt->id,
                    'quiz_id' => $attempt->quiz_id,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status->value,
                    'started_at' => $attempt->started_at?->toIso8601String(),
                ],
                'questions' => $questions->map(
                    fn ($q) => (new StudentQuestionResource($q))->toArray($request)
                )->values(),
            ],
        ]);
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
            'answers.*.numeric_answer' => ['nullable', 'numeric'],
            'answers.*.matching_answer' => ['nullable', 'array'],
            'answers.*.ordering_answer' => ['nullable', 'array'],
            'answers.*.interactive_answer' => ['nullable', 'array'],
            'answers.*.client_result' => ['nullable', 'array'],
        ]);

        try {
            $graded = $this->quizAttempts->submit($attempt, $validated['answers']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $graded->loadMissing('answers');
        $includeExplanation = in_array($graded->status, [AttemptStatus::Graded, AttemptStatus::PendingReview], true);

        $correctAnswers = $graded->answers->where('is_correct', true)->count();
        $wrongAnswers = $graded->answers->where('is_correct', false)->count();
        $pending = $graded->answers->whereNull('is_correct')->count();

        $questions = $this->quizAttempts->questionsForAttempt($graded);

        return response()->json([
            'data' => [
                'attempt_id' => $graded->id,
                'attempt_number' => $graded->attempt_number,
                'score' => (float) $graded->score,
                'max_score' => (float) $graded->max_score,
                'percentage' => (float) $graded->percentage,
                'total_questions' => $questions->count(),
                'correct_answers' => $correctAnswers,
                'wrong_answers' => $wrongAnswers,
                'pending_review' => $pending,
                'passed' => $graded->passed,
                'status' => $graded->status === AttemptStatus::PendingReview
                    ? 'pending_review'
                    : ($graded->passed ? 'passed' : 'failed'),
                'submitted_at' => $graded->submitted_at?->toIso8601String(),
                'questions' => $includeExplanation
                    ? $questions->map(fn ($q) => (new StudentQuestionResource($q, true))->toArray($request))->values()
                    : [],
            ],
        ]);
    }

    public function result(Request $request, QuizAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($attempt->status === AttemptStatus::InProgress) {
            return response()->json(['message' => 'Attempt not submitted.', 'code' => 'ATTEMPT_IN_PROGRESS'], 422);
        }

        $attempt->load(['answers']);
        $questions = $this->quizAttempts->questionsForAttempt($attempt);
        $byId = $questions->keyBy('id');

        return response()->json([
            'data' => [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status->value,
                'score' => (float) $attempt->score,
                'max_score' => (float) $attempt->max_score,
                'percentage' => (float) $attempt->percentage,
                'passed' => $attempt->passed,
                'answers' => $attempt->answers->map(function ($answer) use ($request, $byId) {
                    $question = $byId->get($answer->question_id);

                    return [
                        'question_id' => $answer->question_id,
                        'is_correct' => $answer->is_correct,
                        'points_awarded' => $answer->points_awarded !== null ? (float) $answer->points_awarded : null,
                        'needs_manual_review' => (bool) $answer->needs_manual_review,
                        'question' => $question
                            ? (new StudentQuestionResource($question, true))->toArray($request)
                            : null,
                    ];
                })->values(),
            ],
        ]);
    }

    private function resolveLesson(Quiz $quiz): Lesson
    {
        $lesson = $quiz->quizable;

        if (! $lesson instanceof Lesson) {
            abort(404, 'Quiz not found for lesson.');
        }

        return $lesson;
    }

    private function codeFromMessage(string $message): string
    {
        if (str_starts_with($message, 'MAX_ATTEMPTS_REACHED')) {
            return 'MAX_ATTEMPTS_REACHED';
        }
        if (str_starts_with($message, 'INSUFFICIENT_QUESTIONS')) {
            return 'INSUFFICIENT_QUESTIONS';
        }

        return 'QUIZ_ERROR';
    }
}
