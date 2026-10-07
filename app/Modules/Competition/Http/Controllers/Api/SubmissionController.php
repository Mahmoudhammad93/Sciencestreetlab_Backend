<?php

declare(strict_types=1);

namespace App\Modules\Competition\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\CompetitionSubmissionService;
use App\Modules\Competition\Application\Support\CompetitionSlugResolver;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubmissionController extends Controller
{
    public function __construct(
        private readonly CompetitionSubmissionService $submissions,
        private readonly CompetitionSlugResolver $slugResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = CompetitionSubmission::query()
            ->whereHas('participant', fn ($q) => $q->where('user_id', $request->user()->id));

        if ($request->filled('competition')) {
            $competition = $this->slugResolver->resolve((string) $request->query('competition'));
            $query->whereHas('participant', fn ($q) => $q->where('competition_id', $competition->id));
        }

        $items = $query
            ->with(['participant.competition', 'media'])
            ->latest('submitted_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn (CompetitionSubmission $s) => $this->transform($s)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);

        $rules = $competition->uploadRules();

        $validated = $request->validate([
            'sample_number' => ['required', 'integer', 'min:'.$rules['min_sample_number'], 'max:'.$rules['max_sample_number']],
            'photo_index' => ['nullable', 'integer', 'min:1', 'max:'.$rules['max_photos_per_sample']],
            'description' => ['nullable', 'string', 'max:'.$rules['description_max']],
            'scientific_notes' => ['nullable', 'string', 'max:'.$rules['description_max']],
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$rules['max_file_kb'],
            ],
        ]);

        try {
            $submission = $this->submissions->submit(
                $request->user(),
                $competition,
                $request->file('photo'),
                $validated
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->transform($submission)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $submission = CompetitionSubmission::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'scientific_notes' => ['nullable', 'string', 'max:2000'],
            'photo' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ]);

        try {
            if ($request->hasFile('photo')) {
                $submission = $this->submissions->replacePhoto(
                    $request->user(),
                    $submission,
                    $request->file('photo'),
                    $validated
                );
            } else {
                $submission = $this->submissions->updateMetadata($request->user(), $submission, $validated);
            }
        } catch (DomainException $e) {
            $status = $e->getMessage() === 'forbidden' ? 403 : 422;

            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $this->transform($submission)]);
    }

    /** @return array<string, mixed> */
    private function transform(CompetitionSubmission $submission): array
    {
        $submission->loadMissing(['participant.competition', 'media']);

        $media = $submission->getFirstMedia('photo');
        $photoUrl = $media?->getUrl();
        $thumbUrl = $media && $media->hasGeneratedConversion('thumb')
            ? $media->getUrl('thumb')
            : $photoUrl;

        return [
            'id' => $submission->id,
            'uuid' => $submission->uuid,
            'competition_slug' => $submission->participant->competition->slug,
            'sample_number' => $submission->sample_number,
            'photo_index' => $submission->photo_index,
            'sample_name' => $submission->sample_name,
            'status' => $submission->status->value,
            'description' => $submission->description,
            'scientific_notes' => $submission->scientific_notes,
            'rejection_reason' => $submission->rejection_reason,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'can_replace' => $submission->isReplaceable(),
            'photo_url' => $photoUrl,
            'photo_thumb_url' => $thumbUrl,
        ];
    }
}
