<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Application\Services;

use App\Models\User;
use App\Modules\Gamification\Infrastructure\Persistence\Models\PointTransaction;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserPoints;
use Illuminate\Support\Facades\DB;

final class PointsService
{
    public function add(User $user, int $amount, ?string $sourceType = null, ?int $sourceId = null, ?string $description = null): UserPoints
    {
        return DB::transaction(function () use ($user, $amount, $sourceType, $sourceId, $description): UserPoints {
            $record = UserPoints::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['total_points' => 0]
            );

            $record->increment('total_points', $amount);

            PointTransaction::query()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'created_at' => now(),
            ]);

            return $record->fresh();
        });
    }

    public function forUser(User $user): UserPoints
    {
        return UserPoints::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['total_points' => 0]
        );
    }
}
