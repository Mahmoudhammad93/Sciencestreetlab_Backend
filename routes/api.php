<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'app' => config('sciencestreet.name'),
            'version' => config('sciencestreet.api_version'),
            'status' => 'ok',
            'locale' => app()->getLocale(),
        ]);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'register']);
        Route::post('/login', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'login']);
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'logout']);
            Route::post('/refresh', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'refresh']);
            Route::delete('/me', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'destroyAccount']);
            Route::get('/me', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'me']);
        });
    });
});
