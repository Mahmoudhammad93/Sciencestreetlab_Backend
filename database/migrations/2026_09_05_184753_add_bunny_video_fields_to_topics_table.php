<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->string('bunny_video_id')->nullable()->after('video_provider');
            $table->string('video_status')->nullable()->after('bunny_video_id');
            $table->unsignedTinyInteger('video_progress')->default(0)->after('video_status');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn(['bunny_video_id', 'video_status', 'video_progress']);
        });
    }
};