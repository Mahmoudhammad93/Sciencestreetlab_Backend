<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('category', 30);
            $table->unsignedInteger('points')->default(0);
            $table->string('icon_path', 500)->nullable();
            $table->string('badge_color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->timestamps();
        });

        Schema::create('achievement_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_event');
            $table->json('conditions');
            $table->timestamps();
        });

        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'achievement_id']);
        });

        Schema::create('user_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_points')->default(0);
            $table->timestamps();
        });

        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('user_points');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievement_rules');
        Schema::dropIfExists('achievements');
    }
};
