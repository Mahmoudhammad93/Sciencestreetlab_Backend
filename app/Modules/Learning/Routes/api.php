<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\Api\CourseController;
use App\Modules\Learning\Http\Controllers\Api\CourseLeaderboardController;
use App\Modules\Learning\Http\Controllers\Api\EnrollmentController;
use App\Modules\Learning\Http\Controllers\Api\LessonController;
use App\Modules\Learning\Http\Controllers\Api\TopicProgressController;
use Illuminate\Support\Facades\Route;

Route::prefix('courses')->group(function (): void {
    Route::get('/', [CourseController::class, 'index']);
    Route::get('/{slug}', [CourseController::class, 'show']);
    Route::get('/{slug}/lessons', [LessonController::class, 'index']);
    Route::get('/{slug}/leaderboard', [CourseLeaderboardController::class, 'show'])
        ->middleware('auth.optional');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/{slug}/curriculum', [EnrollmentController::class, 'curriculum']);
        Route::post('/{slug}/enroll', [EnrollmentController::class, 'enroll']);
        Route::get('/{slug}/enrollment', [EnrollmentController::class, 'forCourse']);
        Route::get('/{slug}/access', [EnrollmentController::class, 'access']);
        Route::get('/{slug}/progress', [EnrollmentController::class, 'progress']);
        Route::post('/{slug}/plans/{planId}/enroll', [EnrollmentController::class, 'enrollPlan']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me/enrollments', [EnrollmentController::class, 'index']);
    Route::get('/me/enrollments/{id}', [EnrollmentController::class, 'show']);
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::get('/enrollments/{id}', [EnrollmentController::class, 'show']);
    Route::get('/lessons/{lesson}', [LessonController::class, 'show']);
    Route::post('/topics/{topic}/progress', [TopicProgressController::class, 'reportProgress']);
    Route::post('/topics/{topic}/heartbeat', [TopicProgressController::class, 'heartbeat']);
    Route::get('/topics/{topic}/video-url', [TopicProgressController::class, 'videoUrl']);
});
