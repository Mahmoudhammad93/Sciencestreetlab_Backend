<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1 preparation only — DO NOT run against staging until M2 is authorized.
 *
 * Mirrors topics.video_url / topics.video_provider conventions so LearnDash
 * lesson YouTube references can be stored without inventing a second schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('video_url', 500)->nullable()->after('video_duration_seconds');
            $table->string('video_provider', 20)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['video_url', 'video_provider']);
        });
    }
};
