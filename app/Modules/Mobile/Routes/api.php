<?php

declare(strict_types=1);

use App\Modules\Mobile\Http\Controllers\Api\MobileHomeController;
use App\Modules\Mobile\Http\Controllers\Api\MobileLearningController;
use App\Modules\Mobile\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('mobile')->group(function (): void {
    Route::get('/home', MobileHomeController::class);
    Route::get('/learning-dashboard', MobileLearningController::class);
});

Route::middleware('auth:sanctum')->prefix('sync')->group(function (): void {
    Route::get('/enrollments', [SyncController::class, 'enrollments']);
});
