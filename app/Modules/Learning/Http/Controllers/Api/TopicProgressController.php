<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TopicProgressController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CourseProgressService $progress,
    ) {}

    public function reportProgress(Request $request, Topic $topic): JsonResponse
    {
        $course = $topic->lesson->course;

        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $course);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NOT_ENROLLED'], 403);
        }

        if (! $this->access->canAccessTopic($enrollment, $topic)) {
            return response()->json(['message' => 'Topic is locked.'], 403);
        }

        $validated = $request->validate([
            'watch_progress_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watched_seconds' => ['nullable', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'completed' => ['nullable', 'boolean'],
        ]);

        if (
            ! isset($validated['watch_progress_percent'])
            && ! isset($validated['watched_seconds'])
            && empty($validated['completed'])
        ) {
            return response()->json([
                'message' => 'Provide watch_progress_percent, watched_seconds, or completed.',
            ], 422);
        }

        try {
            $data = $this->progress->recordTopicProgress($enrollment, $topic, $validated);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function heartbeat(Request $request, Topic $topic): JsonResponse
    {
        return $this->reportProgress($request, $topic);
    }

    public function videoUrl(Request $request, Topic $topic): JsonResponse
    {
        $course = $topic->lesson->course;

        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $course);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NOT_ENROLLED'], 403);
        }

        if (! $this->access->canAccessTopic($enrollment, $topic)) {
            return response()->json(['message' => 'Topic is locked.'], 403);
        }

        if (! $topic->video_url) {
            return response()->json(['message' => 'No video available.'], 404);
        }

        $enrollment->update([
            'last_accessed_lesson_id' => $topic->lesson_id,
            'last_accessed_topic_id' => $topic->id,
            'last_accessed_at' => now(),
        ]);

        $completion = $enrollment->topicCompletions()->where('topic_id', $topic->id)->first();

        return response()->json([
            'data' => [
                'url' => $topic->video_url,
                'provider' => $topic->video_provider,
                'expires_at' => now()->addHours(4)->toIso8601String(),
                'last_position_seconds' => $completion?->last_position_seconds ?? 0,
                'watched_seconds' => $completion?->watched_seconds ?? 0,
                'duration_seconds' => $completion?->duration_seconds,
                'watch_progress_percent' => $completion ? (float) $completion->watch_progress_percent : 0,
            ],
        ]);
    }
}
