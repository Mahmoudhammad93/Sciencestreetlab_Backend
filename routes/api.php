<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Http;
Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'app' => config('sciencestreet.name'),
            'version' => config('sciencestreet.api_version'),
            'status' => 'ok',
            'locale' => app()->getLocale(),
        ]);
    });

    Route::get('/settings', [\App\Http\Controllers\Api\PublicSettingsController::class, 'show']);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'register'])
            ->middleware('throttle:auth-register');
        Route::post('/login', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'login'])
            ->middleware('throttle:auth-login');
        Route::post('/forgot-password', [\App\Modules\Identity\Http\Controllers\Api\PasswordResetController::class, 'forgotPassword'])
            ->middleware('throttle:auth-password-reset');
        Route::post('/reset-password', [\App\Modules\Identity\Http\Controllers\Api\PasswordResetController::class, 'resetPassword'])
            ->middleware('throttle:auth-password-reset');
        Route::get('/email/verify/{id}/{hash}', [\App\Modules\Identity\Http\Controllers\Api\EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'logout']);
            Route::post('/refresh', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'refresh']);
            Route::delete('/me', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'destroyAccount']);
            Route::get('/me', [\App\Modules\Identity\Http\Controllers\Api\AuthController::class, 'me']);
            Route::post('/email/verification-notification', [\App\Modules\Identity\Http\Controllers\Api\EmailVerificationController::class, 'resend'])
                ->middleware('throttle:auth-verification-resend');
        });
    });


Route::get('/test-bunny', function () {
    $libraryId = config('services.bunny.stream.library_id');
    $apiKey = config('services.bunny.stream.api_key');

    $response = Http::withHeaders([
        'AccessKey' => $apiKey,
        'Accept' => 'application/json',
    ])->get(
        "https://video.bunnycdn.com/library/{$libraryId}/videos"
    );

    return response()->json([
        'status' => $response->status(),
        'body' => $response->json(),
    ]);
});
Route::get('/test-bunny-create', function (
    \App\Services\BunnyStreamService $bunny
) {
    return response()->json(
        $bunny->createVideo('Test Lesson Video')
    );
});
});
