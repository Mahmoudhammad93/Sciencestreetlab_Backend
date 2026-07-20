<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserAchievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AchievementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $achievements = UserAchievement::query()
            ->where('user_id', $request->user()->id)
            ->with('achievement')
            ->latest('awarded_at')
            ->get()
            ->map(fn (UserAchievement $record) => [
                'slug' => $record->achievement->slug,
                'name' => $record->achievement->getTranslations('name'),
                'description' => $record->achievement->getTranslations('description'),
                'category' => $record->achievement->category->value,
                'points' => $record->achievement->points,
                'badge_color' => $record->achievement->badge_color,
                'awarded_at' => $record->awarded_at->toIso8601String(),
            ]);

        return response()->json(['data' => $achievements]);
    }
}
