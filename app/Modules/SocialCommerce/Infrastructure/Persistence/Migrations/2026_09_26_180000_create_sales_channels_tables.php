<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channel_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 50)->index();
            $table->string('name');
            $table->string('connection_status', 40)->default('not_connected')->index();
            $table->string('sync_status', 40)->default('never_synced')->index();
            $table->string('health_status', 40)->default('unavailable')->index();
            $table->string('external_account_id')->nullable();
            $table->string('external_account_name')->nullable();
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->json('settings')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'name']);
        });

        Schema::create('sales_channel_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('sales_channel_integrations')->cascadeOnDelete();
            $table->string('external_product_id')->nullable();
            $table->string('publication_status', 40)->default('not_published')->index();
            $table->string('sync_status', 40)->default('never_synced')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('readiness_issues')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'integration_id']);
            $table->index(['integration_id', 'publication_status']);
        });

        Schema::create('sales_channel_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')
                ->nullable()
                ->constrained('sales_channel_integrations')
                ->nullOnDelete();
            $table->string('level', 20)->default('info'); // info|success|warning|error
            $table->string('event_code', 80);
            $table->string('message_key');
            $table->json('message_params')->nullable();
            $table->json('technical_context')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channel_activity_logs');
        Schema::dropIfExists('sales_channel_products');
        Schema::dropIfExists('sales_channel_integrations');
    }
};
