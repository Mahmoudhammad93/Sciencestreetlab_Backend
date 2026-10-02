<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow historical WordPress quiz-attempt imports without inventing enrollments.
 *
 * Live QuizAttemptService/API still requires a real Enrollment argument.
 * Only historical importer may persist enrollment_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropForeign(['enrollment_id']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('enrollment_id')->nullable()->change();
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->foreign('enrollment_id')
                ->references('id')
                ->on('enrollments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Cannot safely re-require NOT NULL if historical null rows exist.
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropForeign(['enrollment_id']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('enrollment_id')->nullable(false)->change();
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->foreign('enrollment_id')
                ->references('id')
                ->on('enrollments')
                ->cascadeOnDelete();
        });
    }
};
