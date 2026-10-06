<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete()->unique();
            $table->uuid('order_uuid')->unique();
            $table->char('visitor_key', 36)->nullable()->index();
            $table->foreignId('attribution_session_id')->nullable()->constrained('attribution_sessions')->nullOnDelete();
            $table->string('converting_platform', 40)->nullable()->index();
            $table->unsignedBigInteger('converting_campaign_id')->nullable()->index();
            $table->unsignedBigInteger('converting_content_id')->nullable()->index();
            $table->unsignedBigInteger('converting_tracking_link_id')->nullable()->index();
            $table->string('converting_code', 64)->nullable()->index();
            $table->json('first_touch')->nullable();
            $table->json('last_touch')->nullable();
            $table->string('attribution_model', 40)->default('last_touch_v1');
            $table->unsignedInteger('window_days');
            $table->boolean('is_attributed')->default(false)->index();
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attributions');
    }
};
