<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactive_activities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->string('activity_type', 50)->default('custom')->index();
            $table->string('difficulty', 20)->default('medium')->index();
            $table->decimal('points', 8, 2)->default(10);
            $table->unsignedInteger('estimated_time_seconds')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('activity_package_path', 500)->nullable();
            $table->string('entry_file', 255)->default('index.html');
            $table->json('activity_config')->nullable();
            $table->json('title');
            $table->json('description')->nullable();
            $table->json('instructions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lesson_id', 'status']);
        });

        Schema::create('interactive_activity_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('interactive_activities')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('quiz_attempt_id')->nullable()->constrained('quiz_attempts')->nullOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 30)->default('in_progress')->index();
            $table->decimal('client_score', 8, 2)->nullable();
            $table->decimal('verified_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('score_verified')->default(false);
            $table->unsignedInteger('time_spent_seconds')->nullable();
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'activity_id', 'status']);
            $table->unique(['user_id', 'activity_id', 'attempt_number'], 'ia_attempts_user_activity_number_uq');
        });

        Schema::create('quiz_interactive_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('interactive_activity_id')->constrained('interactive_activities')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('points', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'interactive_activity_id'], 'quiz_activity_unique');
        });

        if (Schema::hasTable('questions') && ! Schema::hasColumn('questions', 'interactive_activity_id')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->foreignId('interactive_activity_id')
                    ->nullable()
                    ->after('interactive_config')
                    ->constrained('interactive_activities')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('questions', 'interactive_activity_id')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('interactive_activity_id');
            });
        }

        Schema::dropIfExists('quiz_interactive_activity');
        Schema::dropIfExists('interactive_activity_attempts');
        Schema::dropIfExists('interactive_activities');
    }
};
