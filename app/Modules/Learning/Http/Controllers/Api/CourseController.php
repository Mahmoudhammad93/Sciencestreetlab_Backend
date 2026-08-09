<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\CoursePresenter;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseController extends Controller
{
    public function __construct(
        private readonly CoursePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->where('is_published', true)
            ->with(['product'])
            ->withCount(['lessons' => fn ($q) => $q->where('is_published', true)])
            ->orderBy('sort_order')
            ->get();

        $user = $request->user('sanctum');

        return response()->json([
            'data' => $courses->map(
                fn (Course $course) => $this->presenter->present($course, $user)
            )->values(),
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with(['product'])
            ->withCount(['lessons' => fn ($q) => $q->where('is_published', true)])
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        return response()->json([
            'data' => $this->presenter->present(
                $course,
                $request->user('sanctum'),
                includeLessonOutline: true,
            ),
        ]);
    }
}
