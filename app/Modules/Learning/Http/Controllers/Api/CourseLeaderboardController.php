<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\CourseLeaderboardService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseLeaderboardController extends Controller
{
    public function __construct(
        private readonly CourseLeaderboardService $leaderboard,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($course === null) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payload = $this->leaderboard->leaderboard(
            $course,
            $request->user('sanctum'),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
        );

        return response()->json($payload);
    }
}
