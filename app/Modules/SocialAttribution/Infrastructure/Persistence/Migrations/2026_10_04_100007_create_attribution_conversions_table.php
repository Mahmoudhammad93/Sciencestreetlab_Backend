<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->uuid('order_uuid')->nullable()->index();
            $table->string('event_name', 40)->index();
            $table->string('event_id', 80)->unique();
            $table->string('platform', 40)->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->unsignedBigInteger('content_id')->nullable()->index();
            $table->unsignedBigInteger('tracking_link_id')->nullable()->index();
            $table->decimal('value', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->boolean('is_attributed')->default(false)->index();
            $table->json('payload_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'event_name'], 'attribution_conversions_order_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_conversions');
    }
};
