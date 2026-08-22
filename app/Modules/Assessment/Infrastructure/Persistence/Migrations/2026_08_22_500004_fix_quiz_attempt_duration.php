<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, normalize existing data to handle negative/decimal values
        DB::table('quiz_attempts')->update([
            'time_spent_seconds' => DB::raw(
                'CASE WHEN time_spent_seconds IS NULL THEN NULL '
                . 'WHEN time_spent_seconds < 0 THEN 0 '
                . 'ELSE CAST(time_spent_seconds AS UNSIGNED) END'
            ),
        ]);

        // Then alter the column to unsigned integer
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('time_spent_seconds')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            // Revert to original nullable integer (which could store negative values)
            $table->integer('time_spent_seconds')->nullable()->change();
        });
    }
};
