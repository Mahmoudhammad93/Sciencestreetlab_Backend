<?php

declare(strict_types=1);

namespace App\Modules\Competition\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\CompetitionEligibilityService;
use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Application\Support\CompetitionSlugResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompetitionController extends Controller
{
    public function __construct(
        private readonly CompetitionEligibilityService $eligibility,
        private readonly CompetitionRegistrationService $registration,
        private readonly CompetitionSlugResolver $slugResolver,
    ) {}

    public function show(string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);

        if (! in_array($competition->status, ['active', 'judging', 'completed'], true)) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'slug' => $competition->slug,
                'canonical_slug' => 'microscope-100-challenge',
                'title' => $competition->getTranslations('title'),
                'description' => $competition->getTranslations('description'),
                'rules' => $competition->getTranslations('rules'),
                'required_photos' => $competition->required_photos,
                'photos_per_sample' => $competition->photos_per_sample,
                'starts_at' => $competition->starts_at->toIso8601String(),
                'ends_at' => $competition->ends_at->toIso8601String(),
                'status' => $competition->status,
                'prize_amount' => $competition->prize_amount,
                'is_active' => $competition->isActive(),
            ],
        ]);
    }

    public function eligibility(Request $request, string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);
        $status = $this->eligibility->participationStatus($request->user(), $competition);

        return response()->json([
            'data' => [
                ...$status,
                // Legacy key used by older clients/tests.
                'reason' => $status['eligibility_reason'],
            ],
        ]);
    }

    public function register(Request $request, string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);

        try {
            $existingBefore = $competition->participants()
                ->where('user_id', $request->user()->id)
                ->exists();

            $participant = $this->registration->register($request->user(), $competition);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getMessage(),
                'data' => $this->eligibility->participationStatus($request->user(), $competition),
            ], 403);
        }

        $statusCode = $existingBefore ? 200 : 201;

        return response()->json([
            'data' => [
                'participant' => $participant,
                'participation' => $this->eligibility->participationStatus($request->user(), $competition),
            ],
        ], $statusCode);
    }

    public function dashboard(Request $request, string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);
        $user = $request->user();
        $participation = $this->eligibility->participationStatus($user, $competition);

        if (! $participation['registered']) {
            return response()->json([
                'message' => 'Not registered.',
                'code' => 'not_registered',
                'data' => $participation,
            ], 403);
        }

        $participant = $competition->participants()
            ->where('user_id', $user->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'status' => $participant->status->value,
                'approved_count' => $participant->approved_count,
                'pending_count' => $participant->pending_count,
                'rejected_count' => $participant->rejected_count,
                'required_photos' => $competition->required_photos,
                'progress_percent' => round(($participant->approved_count / max(1, $competition->required_photos)) * 100, 2),
                'registered_at' => $participant->registered_at->toIso8601String(),
                'shortlisted_at' => $participant->shortlisted_at?->toIso8601String(),
                'participation' => $participation,
            ],
        ]);
    }

    public function submissionsSummary(Request $request, string $slug): JsonResponse
    {
        $competition = $this->slugResolver->resolve($slug);
        $participation = $this->eligibility->participationStatus($request->user(), $competition);

        if (! $participation['registered']) {
            return response()->json([
                'message' => 'Not registered.',
                'code' => 'not_registered',
                'data' => $participation,
            ], 403);
        }

        $participant = $competition->participants()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'approved_count' => $participant->approved_count,
                'pending_count' => $participant->pending_count,
                'rejected_count' => $participant->rejected_count,
                'required_photos' => $competition->required_photos,
                'progress_percent' => round(($participant->approved_count / max(1, $competition->required_photos)) * 100, 2),
            ],
        ]);
    }
}
