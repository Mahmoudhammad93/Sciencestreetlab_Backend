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
        $lesson = $topic->lesson;
        $course = $lesson->course;
        $enrollment = $this->access->requireEnrollment($request->user(), $course);

        if (! $this->access->canAccessTopic($enrollment, $topic)) {
            return response()->json(['message' => 'Topic is locked.'], 403);
        }

        $validated = $request->validate([
            'watch_progress_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $this->progress->markTopicComplete(
                $enrollment,
                $topic,
                (float) $validated['watch_progress_percent']
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'topic_id' => $topic->id,
                'watch_progress_percent' => (float) $validated['watch_progress_percent'],
                'course_progress_percent' => (float) $enrollment->fresh()->progress_percent,
            ],
        ]);
    }

    public function heartbeat(Request $request, Topic $topic): JsonResponse
    {
        $lesson = $topic->lesson;
        $course = $lesson->course;
        $enrollment = $this->access->requireEnrollment($request->user(), $course);

        if (! $this->access->canAccessTopic($enrollment, $topic)) {
            return response()->json(['message' => 'Topic is locked.'], 403);
        }

        $validated = $request->validate([
            'watch_progress_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->progress->markTopicComplete(
            $enrollment,
            $topic,
            (float) $validated['watch_progress_percent']
        );

        return response()->json(['ok' => true]);
    }

    public function videoUrl(Request $request, Topic $topic): JsonResponse
    {
        $course = $topic->lesson->course;
        $enrollment = $this->access->requireEnrollment($request->user(), $course);

        if (! $this->access->canAccessTopic($enrollment, $topic)) {
            return response()->json(['message' => 'Topic is locked.'], 403);
        }

        if (! $topic->video_url) {
            return response()->json(['message' => 'No video available.'], 404);
        }

        return response()->json([
            'data' => [
                'url' => $topic->video_url,
                'provider' => $topic->video_provider,
                'expires_at' => now()->addHours(4)->toIso8601String(),
            ],
        ]);
    }
}
