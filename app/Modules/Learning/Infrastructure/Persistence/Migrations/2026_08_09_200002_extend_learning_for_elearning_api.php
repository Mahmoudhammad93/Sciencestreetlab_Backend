<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->json('short_description')->nullable()->after('description');
            $table->string('image_url', 500)->nullable()->after('short_description');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('last_accessed_lesson_id')->nullable()->after('expires_at')
                ->constrained('lessons')->nullOnDelete();
            $table->foreignId('last_accessed_topic_id')->nullable()->after('last_accessed_lesson_id')
                ->constrained('topics')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable()->after('last_accessed_topic_id');
        });

        Schema::table('topic_completions', function (Blueprint $table): void {
            $table->unsignedInteger('watched_seconds')->nullable()->after('watch_progress_percent');
            $table->unsignedInteger('duration_seconds')->nullable()->after('watched_seconds');
            $table->unsignedInteger('last_position_seconds')->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('topic_completions', function (Blueprint $table): void {
            $table->dropColumn(['watched_seconds', 'duration_seconds', 'last_position_seconds']);
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_accessed_lesson_id');
            $table->dropConstrainedForeignId('last_accessed_topic_id');
            $table->dropColumn('last_accessed_at');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['short_description', 'image_url']);
        });
    }
};
