<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\InteractiveActivityService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityAttemptStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Http\Requests\StartInteractiveActivityRequest;
use App\Modules\Assessment\Http\Requests\SubmitInteractiveActivityProgressRequest;
use App\Modules\Assessment\Http\Requests\SubmitInteractiveActivityResultRequest;
use App\Modules\Assessment\Http\Resources\InteractiveActivityAttemptResource;
use App\Modules\Assessment\Http\Resources\InteractiveActivityResource;
use App\Modules\Assessment\Http\Resources\InteractiveActivityResultResource;
use App\Modules\Assessment\Http\Support\ApiError;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InteractiveActivityApiController extends Controller
{
    public function __construct(
        private readonly InteractiveActivityService $activities,
        private readonly CourseAccessService $access,
    ) {}

    public function forLesson(Request $request, Lesson $lesson): JsonResponse
    {
        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $lesson->course);
            if (! $this->access->canAccessLesson($enrollment, $lesson)) {
                return ApiError::make('Lesson is locked.', 'QUESTION_LOCKED', 403);
            }
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $items = InteractiveActivity::query()
            ->where('lesson_id', $lesson->id)
            ->where('status', InteractiveActivityStatus::Published)
            ->orderBy('id')
            ->get();

        return InteractiveActivityResource::collection($items)->response();
    }

    public function show(Request $request, InteractiveActivity $activity): JsonResponse
    {
        try {
            $this->activities->authorizeActivity($request->user(), $activity);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new InteractiveActivityResource($activity))->response();
    }

    public function launch(Request $request, InteractiveActivity $activity): JsonResponse
    {
        try {
            $payload = $this->activities->launchPayload($request->user(), $activity);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return response()->json(['data' => $payload]);
    }

    public function startAttempt(StartInteractiveActivityRequest $request, InteractiveActivity $activity): JsonResponse
    {
        try {
            $attempt = $this->activities->startAttempt(
                $request->user(),
                $activity,
                $request->filled('quiz_attempt_id') ? (int) $request->integer('quiz_attempt_id') : null,
            );
            $launch = $this->activities->launchPayload($request->user(), $activity);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new InteractiveActivityAttemptResource($attempt, true, $launch))
            ->response()
            ->setStatusCode(201);
    }

    public function showAttempt(Request $request, InteractiveActivityAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return ApiError::make('Forbidden', 'FORBIDDEN', 403);
        }

        $attempt->load('activity');

        return (new InteractiveActivityAttemptResource($attempt))->response();
    }

    public function submitProgress(
        SubmitInteractiveActivityProgressRequest $request,
        InteractiveActivityAttempt $attempt,
    ): JsonResponse {
        if ($attempt->user_id !== $request->user()->id) {
            return ApiError::make('Forbidden', 'FORBIDDEN', 403);
        }

        try {
            $updated = $this->activities->submitProgress($attempt, $request->validated());
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new InteractiveActivityAttemptResource($updated))->response();
    }

    public function submitResult(
        SubmitInteractiveActivityResultRequest $request,
        InteractiveActivityAttempt $attempt,
    ): JsonResponse {
        if ($attempt->user_id !== $request->user()->id) {
            return ApiError::make('Forbidden', 'FORBIDDEN', 403);
        }

        try {
            $graded = $this->activities->submitResult($attempt, $request->validated());
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        return (new InteractiveActivityResultResource($graded))->response();
    }

    public function result(Request $request, InteractiveActivityAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id !== $request->user()->id) {
            return ApiError::make('Forbidden', 'FORBIDDEN', 403);
        }

        if ($attempt->status === InteractiveActivityAttemptStatus::InProgress) {
            return ApiError::make('Attempt not completed.', 'ATTEMPT_IN_PROGRESS', 422);
        }

        return (new InteractiveActivityResultResource($attempt))->response();
    }
}
