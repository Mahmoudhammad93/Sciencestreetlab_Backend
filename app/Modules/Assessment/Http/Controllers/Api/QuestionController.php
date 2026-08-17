<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuestionAccessService;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Http\Resources\InteractiveQuestionResource;
use App\Modules\Assessment\Http\Resources\QuestionResource;
use App\Modules\Assessment\Http\Support\ApiError;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuestionController extends Controller
{
    public function __construct(
        private readonly QuestionAccessService $questionAccess,
    ) {}

    public function show(Request $request, Question $question): JsonResponse
    {
        try {
            $this->questionAccess->authorizeQuestion($request->user(), $question);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $question->load('options');

        return (new QuestionResource($question))->response();
    }

    public function interactive(Request $request, Question $question): JsonResponse
    {
        try {
            $this->questionAccess->authorizeQuestion($request->user(), $question);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        if ($question->question_type !== QuestionType::InteractiveHtml) {
            return ApiError::make('Question is not interactive HTML.', 'QUESTION_NOT_INTERACTIVE', 422);
        }

        return (new InteractiveQuestionResource($question))->response();
    }
}
