<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('competition_submissions')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 30);
            $table->decimal('score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('competition_winners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('competition_participants')->cascadeOnDelete();
            $table->unsignedInteger('rank')->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prize_claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_winners');
        Schema::dropIfExists('submission_reviews');
    }
};
