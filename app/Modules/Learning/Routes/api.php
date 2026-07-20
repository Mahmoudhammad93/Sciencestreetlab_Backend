<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\Api\CourseController;
use App\Modules\Learning\Http\Controllers\Api\EnrollmentController;
use App\Modules\Learning\Http\Controllers\Api\TopicProgressController;
use Illuminate\Support\Facades\Route;

Route::prefix('courses')->group(function (): void {
    Route::get('/', [CourseController::class, 'index']);
    Route::get('/{slug}', [CourseController::class, 'show']);
    Route::middleware('auth:sanctum')->get('/{slug}/curriculum', [EnrollmentController::class, 'curriculum']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::get('/enrollments/{id}', [EnrollmentController::class, 'show']);
    Route::post('/topics/{topic}/progress', [TopicProgressController::class, 'reportProgress']);
    Route::post('/topics/{topic}/heartbeat', [TopicProgressController::class, 'heartbeat']);
    Route::get('/topics/{topic}/video-url', [TopicProgressController::class, 'videoUrl']);
});
