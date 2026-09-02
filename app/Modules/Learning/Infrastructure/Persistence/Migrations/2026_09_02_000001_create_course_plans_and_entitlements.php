<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_lifetime')->default(true);
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('max_quiz_attempts')->nullable();
            $table->boolean('grant_certificate')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'is_active']);
        });

        Schema::create('course_plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_plan_id')->constrained('course_plans')->cascadeOnDelete();
            $table->string('entitleable_type');
            $table->unsignedBigInteger('entitleable_id');
            $table->timestamps();

            $table->unique(
                ['course_plan_id', 'entitleable_type', 'entitleable_id'],
                'course_plan_entitlements_unique'
            );
            $table->index(['entitleable_type', 'entitleable_id'], 'course_plan_entitlements_morph_index');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('course_plan_id')->nullable()->after('course_id')->constrained('course_plans')->nullOnDelete();
            $table->boolean('grant_certificate')->default(false)->after('expires_at');
        });

        Schema::create('enrollment_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->string('entitleable_type');
            $table->unsignedBigInteger('entitleable_id');
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'entitleable_type', 'entitleable_id'],
                'enrollment_entitlements_unique'
            );
            $table->index(['entitleable_type', 'entitleable_id'], 'enrollment_entitlements_morph_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('course_plan_id')->nullable()->after('course_id')->constrained('course_plans')->nullOnDelete();
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->boolean('is_official')->default(false)->after('passed');
        });

        Schema::create('quiz_official_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'quiz_id'], 'quiz_official_scores_enrollment_quiz_unique');
            $table->unique('quiz_attempt_id', 'quiz_official_scores_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_official_scores');
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropColumn('is_official');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('course_plan_id');
        });
        Schema::dropIfExists('enrollment_entitlements');
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('course_plan_id');
            $table->dropColumn('grant_certificate');
        });
        Schema::dropIfExists('course_plan_entitlements');
        Schema::dropIfExists('course_plans');
    }
};
