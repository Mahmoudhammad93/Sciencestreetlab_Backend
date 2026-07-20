<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('background_path')->nullable();
            $table->json('layout_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('name')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('certificate_number', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('certificate_templates');
            $table->timestamp('issued_at');
            $table->string('pdf_path', 500)->nullable();
            $table->string('verification_code', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
