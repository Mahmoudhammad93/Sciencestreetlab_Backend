<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 32)->default('wordpress');
            $table->string('environment', 32)->default('staging');
            $table->string('status', 32)->default('planned'); // planned|running|completed|rolled_back|failed
            $table->json('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::table('legacy_import_maps', function (Blueprint $table): void {
            $table->foreignId('migration_run_id')
                ->nullable()
                ->after('id')
                ->constrained('legacy_migration_runs')
                ->nullOnDelete();
            $table->index('migration_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_import_maps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('migration_run_id');
        });
        Schema::dropIfExists('legacy_migration_runs');
    }
};
