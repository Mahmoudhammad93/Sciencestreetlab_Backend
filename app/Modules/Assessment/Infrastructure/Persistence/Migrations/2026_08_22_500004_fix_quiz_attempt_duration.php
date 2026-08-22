<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            // Change time_spent_seconds to unsigned integer
            // This ensures the column can only store non-negative values
            $table->unsignedInteger('time_spent_seconds')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            // Revert to original nullable integer
            $table->integer('time_spent_seconds')->nullable()->change();
        });
    }
};
