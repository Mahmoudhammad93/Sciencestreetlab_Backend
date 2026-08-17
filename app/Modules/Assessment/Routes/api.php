<?php

declare(strict_types=1);

use App\Modules\Assessment\Http\Controllers\Api\InteractiveActivityApiController;
use App\Modules\Assessment\Http\Controllers\Api\QuestionBankController;
use App\Modules\Assessment\Http\Controllers\Api\QuestionController;
use App\Modules\Assessment\Http\Controllers\Api\QuizAttemptController;
use App\Modules\Assessment\Http\Controllers\Api\QuizController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Quiz metadata + start attempt (contract)
    Route::get('/quizzes/{quiz}', [QuizAttemptController::class, 'showQuiz']);
    Route::post('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'start']);

    // Contract quiz-attempt paths
    Route::get('/quiz-attempts/{attempt}', [QuizAttemptController::class, 'show']);
    Route::post('/quiz-attempts/{attempt}/answers', [QuizAttemptController::class, 'storeAnswer']);
    Route::post('/quiz-attempts/{attempt}/submit', [QuizAttemptController::class, 'submit']);
    Route::get('/quiz-attempts/{attempt}/result', [QuizAttemptController::class, 'result']);
    Route::post(
        '/quiz-attempts/{attempt}/questions/{question}/interactive-result',
        [QuizAttemptController::class, 'interactiveResult']
    );

    // Legacy attempt aliases (keep LearningFlow / e-learning tests working)
    Route::get('/attempts/{attempt}', [QuizController::class, 'showAttempt']);
    Route::post('/attempts/{attempt}/submit', [QuizController::class, 'submitAttempt']);
    Route::get('/attempts/{attempt}/result', [QuizController::class, 'result']);

    // Question banks
    Route::get('/lessons/{lesson}/question-banks', [QuestionBankController::class, 'forLesson']);
    Route::get('/question-banks/{questionBank}', [QuestionBankController::class, 'show']);
    Route::get('/question-banks/{questionBank}/questions', [QuestionBankController::class, 'questions']);

    // Questions
    Route::get('/questions/{question}', [QuestionController::class, 'show']);
    Route::get('/questions/{question}/interactive', [QuestionController::class, 'interactive']);

    // Interactive activities (first-class HTML/JS packages)
    Route::get('/lessons/{lesson}/interactive-activities', [InteractiveActivityApiController::class, 'forLesson']);
    Route::get('/interactive-activities/{activity}', [InteractiveActivityApiController::class, 'show']);
    Route::get('/interactive-activities/{activity}/launch', [InteractiveActivityApiController::class, 'launch']);
    Route::post('/interactive-activities/{activity}/attempts', [InteractiveActivityApiController::class, 'startAttempt']);
    Route::get('/interactive-activity-attempts/{attempt}', [InteractiveActivityApiController::class, 'showAttempt']);
    Route::post('/interactive-activity-attempts/{attempt}/progress', [InteractiveActivityApiController::class, 'submitProgress']);
    Route::post('/interactive-activity-attempts/{attempt}/result', [InteractiveActivityApiController::class, 'submitResult']);
    Route::get('/interactive-activity-attempts/{attempt}/result', [InteractiveActivityApiController::class, 'result']);
});
