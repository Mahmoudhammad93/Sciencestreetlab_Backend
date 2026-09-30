<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical AQ submissions have free-text sample_name only — no reliable
 * sample_number / photo_index semantic. Allow NULL slots for migrated rows.
 *
 * Normal NEW submissions still require slots via API validation + domain logic.
 * uk_submission_slot remains: non-null combinations stay unique; multiple NULL
 * slot pairs are allowed by SQL unique semantics (NULL ≠ NULL).
 *
 * Safe to re-run against DBs whose create migration already included nullability.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_submissions')) {
            return;
        }

        Schema::table('competition_submissions', function (Blueprint $table): void {
            if (Schema::hasColumn('competition_submissions', 'sample_number')) {
                $table->unsignedInteger('sample_number')->nullable()->change();
            }
            if (Schema::hasColumn('competition_submissions', 'photo_index')) {
                $table->unsignedTinyInteger('photo_index')->nullable()->change();
            }
        });

        if (! Schema::hasColumn('competition_submissions', 'sample_name')) {
            Schema::table('competition_submissions', function (Blueprint $table): void {
                $table->string('sample_name')->nullable()->after('photo_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('competition_submissions')) {
            return;
        }

        if (Schema::hasColumn('competition_submissions', 'sample_name')) {
            Schema::table('competition_submissions', function (Blueprint $table): void {
                $table->dropColumn('sample_name');
            });
        }

        // Do not force NOT NULL on down when historical null rows may exist.
        Schema::table('competition_submissions', function (Blueprint $table): void {
            $table->unsignedInteger('sample_number')->nullable(false)->change();
            $table->unsignedTinyInteger('photo_index')->nullable(false)->default(1)->change();
        });
    }
};
