<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuizAnswerImageUploadService;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class QuizAnswerImageController extends Controller
{
    public function __invoke(
        Request $request,
        QuizAttempt $attempt,
        Question $question,
        int $media,
        QuizAnswerImageUploadService $uploads,
    ): BinaryFileResponse {
        $user = $request->user();
        if ($user === null || ! $user->hasAnyRole(['super_admin', 'content_manager'])) {
            abort(403);
        }

        try {
            $item = $uploads->resolveMedia($attempt, $question, $media);
        } catch (DomainException) {
            abort(404);
        }

        $path = $item->getPath();
        if (! is_string($path) || ! is_file($path)) {
            abort(404);
        }

        return Response::file($path, [
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$item->file_name.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
