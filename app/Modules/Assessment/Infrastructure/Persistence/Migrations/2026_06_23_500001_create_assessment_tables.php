<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('quizable_type');
            $table->unsignedBigInteger('quizable_id');
            $table->decimal('passing_score', 5, 2)->default(70);
            $table->unsignedInteger('max_attempts')->nullable();
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('is_required')->default(true);
            $table->json('title')->nullable();
            $table->json('instructions')->nullable();
            $table->timestamps();
            $table->index(['quizable_type', 'quizable_id']);
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('question_type', 30);
            $table->decimal('points', 5, 2)->default(1);
            $table->integer('sort_order')->default(0);
            $table->json('body')->nullable();
            $table->json('explanation')->nullable();
            $table->timestamps();
        });

        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_correct')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('label')->nullable();
            $table->timestamps();
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('in_progress');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->unsignedInteger('time_spent_seconds')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'quiz_id']);
        });

        Schema::create('quiz_attempt_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->text('text_answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('points_awarded', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
