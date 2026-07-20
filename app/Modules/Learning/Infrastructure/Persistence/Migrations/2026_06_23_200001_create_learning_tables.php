<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('access_type', 20)->default('paid');
            $table->unsignedBigInteger('prerequisite_course_id')->nullable();
            $table->decimal('estimated_hours', 5, 2)->nullable();
            $table->unsignedBigInteger('certificate_template_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('lesson_type', 30)->default('theory');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->json('title')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'slug']);
        });

        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->integer('sort_order')->default(0);
            $table->string('content_type', 20)->default('video');
            $table->string('video_url', 500)->nullable();
            $table->string('video_provider', 20)->nullable();
            $table->boolean('is_published')->default(true);
            $table->json('title')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'slug']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->timestamp('enrolled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('topic_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->decimal('watch_progress_percent', 5, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unique(['enrollment_id', 'topic_id']);
        });

        Schema::create('lesson_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->unsignedInteger('time_spent_seconds')->nullable();
            $table->unique(['enrollment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_completions');
        Schema::dropIfExists('topic_completions');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('courses');
    }
};
