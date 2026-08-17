<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuestionAccessService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Http\Requests\StartQuizRequest;
use App\Modules\Assessment\Http\Requests\SubmitAnswerRequest;
use App\Modules\Assessment\Http\Requests\SubmitInteractiveResultRequest;
use App\Modules\Assessment\Http\Requests\SubmitQuizRequest;
use App\Modules\Assessment\Http\Resources\QuizAttemptResource;
use App\Modules\Assessment\Http\Resources\QuizResource;
use App\Modules\Assessment\Http\Resources\QuizResultResource;
use App\Modules\Assessment\Http\Support\ApiError;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class QuizAttemptController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly QuizAttemptService $quizAttempts,
        private readonly QuestionAccessService $questionAccess,
    ) {}

    public function showQuiz(Request $request, Quiz $quiz): JsonResponse
    {
        try {
            $lesson = $this->resolveLesson($quiz);
            $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);
            if (! $this->access->canAccessQuiz($enrollment, $quiz)) {
                return ApiError::make('Quiz is locked.', 'QUESTION_LOCKED', 403);
            }
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new QuizResource($quiz))->response();
    }

    public function start(StartQuizRequest $request, Quiz $quiz): JsonResponse
    {
        try {
            $lesson = $this->resolveLesson($quiz);
            $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);
            if (! $this->access->canAccessQuiz($enrollment, $quiz)) {
                return ApiError::make('Quiz is locked.', 'QUESTION_LOCKED', 403);
            }

            $attempt = $this->quizAttempts->start($request->user(), $quiz, $enrollment);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $attempt->load(['quiz', 'answers']);

        return (new QuizAttemptResource($attempt))->response()->setStatusCode(201);
    }

    public function show(Request $request, QuizAttempt $attempt): JsonResponse
    {
        try {
            $this->questionAccess->authorizeAttempt($request->user(), $attempt);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $attempt->load(['quiz', 'answers']);

        return (new QuizAttemptResource($attempt))->response();
    }

    public function storeAnswer(SubmitAnswerRequest $request, QuizAttempt $attempt): JsonResponse
    {
        try {
            $this->questionAccess->authorizeAttempt($request->user(), $attempt);
            $answer = $this->quizAttempts->saveAnswer(
                $attempt,
                (int) $request->integer('question_id'),
                $request->validated()
            );
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        } catch (ValidationException $e) {
            return ApiError::validation($e);
        }

        return response()->json([
            'data' => [
                'question_id' => $answer->question_id,
                'saved' => true,
                'attempt_id' => $attempt->id,
            ],
        ]);
    }

    public function submit(SubmitQuizRequest $request, QuizAttempt $attempt): JsonResponse
    {
        try {
            $this->questionAccess->authorizeAttempt($request->user(), $attempt);
            $answers = $request->input('answers', []);
            if ($answers === []) {
                // Finalize using already-saved answers
                $attempt->load('answers');
                $answers = $attempt->answers->map(fn ($a) => [
                    'question_id' => $a->question_id,
                    'selected_option_ids' => $a->selected_option_ids,
                    'text_answer' => $a->text_answer,
                    'numeric_answer' => $a->numeric_answer,
                    'matching_answer' => $a->matching_answer,
                    'ordering_answer' => $a->ordering_answer,
                    'interactive_answer' => $a->interactive_answer,
                    'client_result' => $a->client_result,
                ])->all();
            }

            $graded = $this->quizAttempts->submit($attempt, $answers);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new QuizResultResource($graded))->response();
    }

    public function result(Request $request, QuizAttempt $attempt): JsonResponse
    {
        try {
            $this->questionAccess->authorizeAttempt($request->user(), $attempt);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        if ($attempt->status === AttemptStatus::InProgress) {
            return ApiError::make('Attempt not submitted.', 'ATTEMPT_IN_PROGRESS', 422);
        }

        return (new QuizResultResource($attempt))->response();
    }

    public function interactiveResult(
        SubmitInteractiveResultRequest $request,
        QuizAttempt $attempt,
        Question $question,
    ): JsonResponse {
        try {
            $this->questionAccess->authorizeAttempt($request->user(), $attempt);
            $this->questionAccess->assertQuestionOnAttempt($attempt, $question, $this->quizAttempts);
            $answer = $this->quizAttempts->saveInteractiveResult($attempt, $question, $request->validated());
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return response()->json([
            'data' => [
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'saved' => true,
                'completed' => (bool) ($request->boolean('completed', true)),
                // Explicitly do not echo/trust client score as final
                'server_verified' => false,
                'message' => 'Interactive result stored. Final score is calculated on quiz submit.',
            ],
        ]);
    }

    private function resolveLesson(Quiz $quiz): Lesson
    {
        $lesson = $quiz->quizable;

        if (! $lesson instanceof Lesson) {
            throw new DomainException('QUESTION_NOT_FOUND: Quiz not found for lesson.', 404);
        }

        return $lesson;
    }
}
