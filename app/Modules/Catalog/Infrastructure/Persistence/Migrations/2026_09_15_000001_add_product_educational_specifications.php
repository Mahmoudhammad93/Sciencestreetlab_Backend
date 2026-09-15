<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('difficulty_level', 50)->nullable()->after('category_id');
            $table->string('target_age', 100)->nullable()->after('difficulty_level');
            $table->json('key_benefits')->nullable()->after('target_age');
            $table->json('scientific_concepts')->nullable()->after('key_benefits');
            $table->text('design_lab_description')->nullable()->after('scientific_concepts');
            $table->text('creative_lab_description')->nullable()->after('design_lab_description');
            $table->foreignId('related_course_id')
                ->nullable()
                ->after('creative_lab_description')
                ->constrained('courses')
                ->nullOnDelete();
        });

        Schema::create('product_curriculum_alignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('grade_level', 100);
            $table->string('lesson_name', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_curriculum_alignments');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('related_course_id');
            $table->dropColumn([
                'difficulty_level',
                'target_age',
                'key_benefits',
                'scientific_concepts',
                'design_lab_description',
                'creative_lab_description',
            ]);
        });
    }
};
