<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncController extends Controller
{
    public function enrollments(Request $request): JsonResponse
    {
        $since = $request->query('since');

        $query = Enrollment::query()
            ->where('user_id', $request->user()->id)
            ->with('course:id,slug,title');

        if ($since) {
            $query->where('updated_at', '>=', $since);
        }

        $enrollments = $query->get()->map(fn (Enrollment $e) => [
            'id' => $e->id,
            'course_slug' => $e->course->slug,
            'course_title' => $e->course->getTranslations('title'),
            'progress_percent' => (float) $e->progress_percent,
            'status' => $e->status->value,
            'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'updated_at' => $e->updated_at->toIso8601String(),
        ]);

        return response()->json(['data' => $enrollments]);
    }
}
