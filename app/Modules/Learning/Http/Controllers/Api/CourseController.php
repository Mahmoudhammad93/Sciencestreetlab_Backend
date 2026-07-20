<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Http\JsonResponse;

final class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::query()
            ->where('is_published', true)
            ->withCount('lessons')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $courses]);
    }

    public function show(string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with(['lessons.topics'])
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        return response()->json(['data' => $course]);
    }
}
