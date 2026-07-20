<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('template', 100)->default('default');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('title')->nullable();
            $table->json('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('title')->nullable();
            $table->json('excerpt')->nullable();
            $table->json('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('pages');
    }
};
