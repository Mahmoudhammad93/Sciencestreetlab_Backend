<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->timestamps();
            $table->index(['lesson_id', 'status']);
        });

        Schema::create('question_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->json('name')->nullable();
            $table->timestamps();
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->foreignId('question_bank_id')->nullable()->after('quiz_id')
                ->constrained('question_banks')->nullOnDelete();
            $table->string('difficulty', 20)->default('medium')->after('question_type');
            $table->string('status', 20)->default('published')->after('difficulty');
            $table->string('interactive_type', 50)->nullable()->after('explanation');
            $table->string('interactive_path', 500)->nullable()->after('interactive_type');
            $table->json('interactive_config')->nullable()->after('interactive_path');
            $table->json('answer_key')->nullable()->after('interactive_config');
            $table->index(['question_bank_id', 'status', 'difficulty']);
            $table->index(['status', 'question_type']);
        });

        DB::table('questions')->whereNull('uuid')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('questions')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->unique('uuid');
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->dropForeign(['quiz_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite: recreate nullable FK via Laravel change helper when available
            Schema::table('questions', function (Blueprint $table): void {
                $table->unsignedBigInteger('quiz_id')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE questions MODIFY quiz_id BIGINT UNSIGNED NULL');
        }

        Schema::table('questions', function (Blueprint $table): void {
            $table->foreign('quiz_id')->references('id')->on('quizzes')->nullOnDelete();
        });

        Schema::table('question_options', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('label');
        });

        Schema::create('question_tag', function (Blueprint $table): void {
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('question_tag_id')->constrained('question_tags')->cascadeOnDelete();
            $table->primary(['question_id', 'question_tag_id']);
        });

        Schema::table('quizzes', function (Blueprint $table): void {
            $table->string('selection_mode', 20)->default('fixed')->after('is_required');
            $table->json('selection_config')->nullable()->after('selection_mode');
        });

        Schema::create('quiz_question_bank', function (Blueprint $table): void {
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->primary(['quiz_id', 'question_bank_id']);
        });

        Schema::create('quiz_attempt_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'question_id']);
            $table->index(['quiz_attempt_id', 'sort_order']);
        });

        Schema::table('quiz_attempt_answers', function (Blueprint $table): void {
            $table->decimal('numeric_answer', 12, 4)->nullable()->after('text_answer');
            $table->json('matching_answer')->nullable()->after('numeric_answer');
            $table->json('ordering_answer')->nullable()->after('matching_answer');
            $table->json('interactive_answer')->nullable()->after('ordering_answer');
            $table->json('client_result')->nullable()->after('interactive_answer');
            $table->json('server_result')->nullable()->after('client_result');
            $table->boolean('needs_manual_review')->default(false)->after('server_result');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempt_answers', function (Blueprint $table): void {
            $table->dropColumn([
                'numeric_answer', 'matching_answer', 'ordering_answer',
                'interactive_answer', 'client_result', 'server_result', 'needs_manual_review',
            ]);
        });

        Schema::dropIfExists('quiz_attempt_questions');
        Schema::dropIfExists('quiz_question_bank');

        Schema::table('quizzes', function (Blueprint $table): void {
            $table->dropColumn(['selection_mode', 'selection_config']);
        });

        Schema::dropIfExists('question_tag');

        Schema::table('question_options', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->dropForeign(['quiz_id']);
            $table->dropForeign(['question_bank_id']);
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid', 'question_bank_id', 'difficulty', 'status',
                'interactive_type', 'interactive_path', 'interactive_config', 'answer_key',
            ]);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('questions', function (Blueprint $table): void {
                $table->unsignedBigInteger('quiz_id')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE questions MODIFY quiz_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('questions', function (Blueprint $table): void {
            $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
        });

        Schema::dropIfExists('question_tags');
        Schema::dropIfExists('question_banks');
    }
};
