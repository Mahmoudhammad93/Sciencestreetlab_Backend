<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CurriculumService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CurriculumService $curriculum,
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
        $enrollment = $this->access->requireEnrollment($request->user(), $course);

        return response()->json([
            'data' => $this->curriculum->build($enrollment),
        ]);
    }
}
