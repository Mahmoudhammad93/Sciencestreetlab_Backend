<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->foreignId('prerequisite_course_id')->constrained('courses');
            $table->unsignedInteger('required_photos')->default(100);
            $table->unsignedInteger('photos_per_sample')->default(2);
            $table->unsignedInteger('max_photos_per_sample')->default(2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('draft');
            $table->text('prize_description')->nullable();
            $table->decimal('prize_amount', 12, 2)->nullable();
            $table->unsignedInteger('rules_version')->default(1);
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('competition_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('registered');
            $table->unsignedInteger('approved_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->timestamp('registered_at');
            $table->timestamp('shortlisted_at')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'user_id'], 'uk_comp_participant');
        });

        Schema::create('competition_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('uk_comp_submission_uuid');
            $table->foreignId('participant_id')->constrained('competition_participants')->cascadeOnDelete();
            $table->unsignedInteger('sample_number');
            $table->unsignedTinyInteger('photo_index')->default(1);
            $table->string('status', 30)->default('pending');
            $table->text('description')->nullable();
            $table->text('scientific_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['participant_id', 'sample_number', 'photo_index'], 'uk_submission_slot');
            $table->index('status', 'idx_submission_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_submissions');
        Schema::dropIfExists('competition_participants');
        Schema::dropIfExists('competitions');
    }
};
