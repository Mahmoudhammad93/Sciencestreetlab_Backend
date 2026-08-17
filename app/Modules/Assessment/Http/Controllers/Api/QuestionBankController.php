<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuestionAccessService;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Http\Requests\QuestionBankFilterRequest;
use App\Modules\Assessment\Http\Resources\QuestionBankResource;
use App\Modules\Assessment\Http\Resources\QuestionResource;
use App\Modules\Assessment\Http\Support\ApiError;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuestionBankController extends Controller
{
    public function __construct(
        private readonly QuestionAccessService $questionAccess,
    ) {}

    public function forLesson(Request $request, Lesson $lesson): JsonResponse
    {
        try {
            $this->questionAccess->authorizeLesson($request->user(), $lesson);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $banks = QuestionBank::query()
            ->where('lesson_id', $lesson->id)
            ->where('status', QuestionBankStatus::Active)
            ->with(['lesson', 'quizzes'])
            ->withCount(['questions as published_questions_count' => fn ($q) => $q->where('status', QuestionStatus::Published)])
            ->orderBy('id')
            ->get();

        return QuestionBankResource::collection($banks)->response();
    }

    public function show(Request $request, QuestionBank $questionBank): JsonResponse
    {
        try {
            $this->questionAccess->authorizeBank($request->user(), $questionBank);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $questionBank->load(['lesson', 'quizzes']);
        $questionBank->loadCount([
            'questions as published_questions_count' => fn ($q) => $q->where('status', QuestionStatus::Published),
        ]);

        return (new QuestionBankResource($questionBank))->response();
    }

    public function questions(QuestionBankFilterRequest $request, QuestionBank $questionBank): JsonResponse
    {
        try {
            $this->questionAccess->authorizeBank($request->user(), $questionBank);
        } catch (DomainException $e) {
            return ApiError::fromDomain($e);
        }

        $type = $request->input('question_type', $request->input('type'));

        $query = $questionBank->questions()
            ->with('options')
            ->where('status', QuestionStatus::Published)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->string('difficulty'));
        }
        if ($type) {
            $query->where('question_type', $type);
        }
        if ($request->filled('interactive_type')) {
            $query->where('interactive_type', $request->string('interactive_type'));
        }

        $tagSlugs = [];
        if ($request->filled('tag')) {
            $tagSlugs[] = (string) $request->string('tag');
        }
        if ($request->filled('tags') && is_array($request->input('tags'))) {
            $tagSlugs = array_merge($tagSlugs, array_map('strval', $request->input('tags')));
        }
        if ($tagSlugs !== []) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('slug', array_unique($tagSlugs)));
        }

        $query->with('tags');

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        return QuestionResource::collection($paginator)->response();
    }
}
