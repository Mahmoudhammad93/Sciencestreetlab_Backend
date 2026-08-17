<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves untrusted interactive packages without Sanctum session cookies.
 * Frontend must load inside sandboxed iframe (no allow-same-origin).
 */
final class InteractiveActivityController extends Controller
{
    public function __construct(
        private readonly InteractiveQuestionStorageService $questionStorage,
        private readonly InteractiveActivityPackageService $activityPackages,
    ) {}

    /**
     * Legacy: interactive_html Question packages under interactive-questions/{uuid}/
     */
    public function show(Request $request, string $uuid, string $path = 'activity.html'): BinaryFileResponse|Response
    {
        $question = Question::query()->where('uuid', $uuid)->firstOrFail();

        if ($question->question_type !== QuestionType::InteractiveHtml
            || $question->status !== QuestionStatus::Published) {
            abort(404);
        }

        $path = $this->sanitizePath($path, 'activity.html');
        $isEntry = basename($path) === 'activity.html' || basename($path) === 'index.html';
        if ($isEntry && ! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired interactive URL signature.');
        }

        $relative = $this->questionStorage->basePath($question).'/'.$path;
        $disk = Storage::disk('public');

        if (! $disk->exists($relative)) {
            abort(404);
        }

        return $this->fileResponse($disk->path($relative));
    }

    /**
     * First-class InteractiveActivity packages:
     * interactive-activities/{uuid}/v{n}/{path}
     */
    public function showPackage(
        Request $request,
        string $uuid,
        int $version,
        string $path = 'index.html',
    ): BinaryFileResponse|Response {
        $activity = InteractiveActivity::query()->where('uuid', $uuid)->firstOrFail();

        if ($activity->status !== InteractiveActivityStatus::Published) {
            abort(404);
        }

        $path = $this->sanitizePath($path, $activity->entry_file ?: 'index.html');
        $isEntry = basename($path) === basename($activity->entry_file ?: 'index.html');
        if ($isEntry && ! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired interactive URL signature.');
        }

        $relative = $this->activityPackages->basePath($activity, $version).'/'.$path;
        $disk = Storage::disk('public');

        if (! $disk->exists($relative)) {
            abort(404);
        }

        return $this->fileResponse($disk->path($relative));
    }

    private function sanitizePath(?string $path, string $default): string
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || $path === null) {
            $path = $default;
        }
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }

        return $path;
    }

    private function fileResponse(string $absolute): BinaryFileResponse|Response
    {
        $mime = match (strtolower(pathinfo($absolute, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => 'application/octet-stream',
        };

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:;",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
