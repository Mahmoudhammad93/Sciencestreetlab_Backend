<?php

declare(strict_types=1);

use App\Modules\Assessment\Http\Controllers\Api\QuizController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/attempts', [QuizController::class, 'startAttempt']);
    Route::post('/attempts/{attempt}/submit', [QuizController::class, 'submitAttempt']);
    Route::get('/attempts/{attempt}/result', [QuizController::class, 'result']);
});
