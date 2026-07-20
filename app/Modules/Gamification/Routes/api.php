<?php

declare(strict_types=1);

use App\Modules\Gamification\Http\Controllers\Api\AchievementController;
use App\Modules\Gamification\Http\Controllers\Api\PointsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me/achievements', [AchievementController::class, 'index']);
    Route::get('/me/points', [PointsController::class, 'show']);
});
