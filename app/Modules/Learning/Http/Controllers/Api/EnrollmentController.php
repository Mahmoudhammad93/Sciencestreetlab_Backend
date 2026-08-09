<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CoursePresenter;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Application\Services\CurriculumService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CurriculumService $curriculum,
        private readonly EnrollUserService $enrollUser,
        private readonly CourseProgressService $progress,
        private readonly CoursePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $enrollments = $request->user()
            ->enrollments()
            ->with('course')
            ->latest('enrolled_at')
            ->get();

        return response()->json(['data' => $enrollments]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $enrollment = $request->user()
            ->enrollments()
            ->with('course')
            ->findOrFail($id);

        return response()->json(['data' => $enrollment]);
    }

    public function curriculum(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $course);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NOT_ENROLLED'], 403);
        }

        return response()->json([
            'data' => $this->curriculum->build($enrollment),
        ]);
    }

    public function enroll(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

        try {
            $result = $this->enrollUser->enrollDirect($request->user(), $course);
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => match ($status) {
                    402 => 'PAYMENT_REQUIRED',
                    403 => 'ENROLLMENT_FORBIDDEN',
                    default => 'ENROLLMENT_FAILED',
                },
                'access_type' => $course->access_type->value,
                'product_id' => $course->product_id,
            ], $status);
        }

        $enrollment = $result['enrollment']->load('course');

        return response()->json([
            'data' => [
                'enrollment' => $enrollment,
                'course' => $this->presenter->present($course, $request->user()),
                'already_enrolled' => ! $result['created'],
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function forCourse(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();
        $enrollment = $this->access->enrollmentFor($request->user(), $course);

        if (! $enrollment) {
            return response()->json([
                'message' => 'Not enrolled in this course.',
                'code' => 'NOT_ENROLLED',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => $enrollment->load(['course', 'lastAccessedLesson', 'lastAccessedTopic']),
        ]);
    }

    public function progress(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $course);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NOT_ENROLLED'], 403);
        }

        return response()->json([
            'data' => $this->progress->courseProgressPayload($enrollment),
        ]);
    }
}
