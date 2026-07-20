<?php

declare(strict_types=1);

use App\Modules\Competition\Http\Controllers\Api\CompetitionController;
use App\Modules\Competition\Http\Controllers\Api\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('competitions')->group(function (): void {
    Route::get('/{slug}', [CompetitionController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/{slug}/eligibility', [CompetitionController::class, 'eligibility']);
        Route::post('/{slug}/register', [CompetitionController::class, 'register']);
        Route::get('/{slug}/dashboard', [CompetitionController::class, 'dashboard']);
        Route::get('/{slug}/submissions/summary', [CompetitionController::class, 'submissionsSummary']);
        Route::post('/{slug}/submissions', [SubmissionController::class, 'store']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/submissions', [SubmissionController::class, 'index']);
    Route::put('/submissions/{uuid}', [SubmissionController::class, 'update']);
});
