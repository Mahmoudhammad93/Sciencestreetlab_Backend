<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('visitor_key', 36)->unique();
            $table->string('cart_session_id', 128)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('window_starts_at');
            $table->timestamp('window_ends_at')->index();
            $table->foreignId('first_tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->unsignedBigInteger('first_campaign_id')->nullable()->index();
            $table->unsignedBigInteger('first_content_id')->nullable()->index();
            $table->string('first_platform', 40)->nullable()->index();
            $table->timestamp('first_touched_at')->nullable();
            $table->foreignId('last_tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->unsignedBigInteger('last_campaign_id')->nullable()->index();
            $table->unsignedBigInteger('last_content_id')->nullable()->index();
            $table->string('last_platform', 40)->nullable()->index();
            $table->timestamp('last_touched_at')->nullable()->index();
            $table->json('first_touch')->nullable();
            $table->json('last_touch')->nullable();
            $table->string('fbp', 255)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->boolean('consent_marketing')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_sessions');
    }
};
