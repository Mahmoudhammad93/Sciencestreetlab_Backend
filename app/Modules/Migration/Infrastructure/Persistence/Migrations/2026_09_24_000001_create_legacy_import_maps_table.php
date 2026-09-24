<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32)->default('wordpress');
            $table->string('entity_type', 32); // user|course|enrollment|order|transaction
            $table->string('legacy_id');
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('legacy_email')->nullable();
            $table->string('checksum')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'entity_type', 'legacy_id'], 'legacy_import_maps_source_entity_legacy_unique');
            $table->index(['entity_type', 'local_id']);
            $table->index('legacy_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_maps');
    }
};
