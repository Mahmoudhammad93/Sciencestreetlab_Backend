<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_slides', function (Blueprint $table): void {
            $table->unsignedInteger('display_duration_seconds')->default(6)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('home_slides', function (Blueprint $table): void {
            $table->dropColumn('display_duration_seconds');
        });
    }
};
