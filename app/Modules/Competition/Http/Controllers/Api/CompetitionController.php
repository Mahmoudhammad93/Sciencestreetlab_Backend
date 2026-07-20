<?php

declare(strict_types=1);

namespace App\Modules\Competition\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\CompetitionEligibilityService;
use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompetitionController extends Controller
{
    public function __construct(
        private readonly CompetitionEligibilityService $eligibility,
        private readonly CompetitionRegistrationService $registration,
    ) {}

    public function show(string $slug): JsonResponse
    {
        $competition = Competition::query()
            ->where('slug', $slug)
            ->whereIn('status', ['active', 'judging', 'completed'])
            ->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $competition->slug,
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
        $competition = Competition::query()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => $this->eligibility->canParticipate($request->user(), $competition),
        ]);
    }

    public function register(Request $request, string $slug): JsonResponse
    {
        $competition = Competition::query()->where('slug', $slug)->firstOrFail();

        try {
            $participant = $this->registration->register($request->user(), $competition);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $participant], 201);
    }

    public function dashboard(Request $request, string $slug): JsonResponse
    {
        $competition = Competition::query()->where('slug', $slug)->firstOrFail();

        $participant = $competition->participants()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Not registered.', 'code' => 'not_registered'], 404);
        }

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
            ],
        ]);
    }

    public function submissionsSummary(Request $request, string $slug): JsonResponse
    {
        $competition = Competition::query()->where('slug', $slug)->firstOrFail();

        $participant = $competition->participants()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Not registered.', 'code' => 'not_registered'], 404);
        }

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
