<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_link_clicks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tracking_link_id')->constrained('tracking_links')->restrictOnDelete();
            $table->foreignId('attribution_session_id')->nullable()->constrained('attribution_sessions')->nullOnDelete();
            $table->char('visitor_key', 36)->index();
            $table->timestamp('occurred_at')->index();
            $table->boolean('is_bot')->default(false)->index();
            $table->boolean('is_unique_human')->default(false)->index();
            /*
             | Race-safe unique human claim key:
             | "{tracking_link_id}:{visitor_key}:{Y-m-d}" when is_unique_human=true.
             | NULL for bots / duplicate same-day human clicks (MySQL allows many NULLs).
             */
            $table->string('unique_click_key', 191)->nullable()->unique();
            $table->string('referrer_host', 255)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('ttclid', 255)->nullable();
            $table->string('request_path', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tracking_link_id', 'occurred_at'], 'tracking_link_clicks_link_time_idx');
            $table->index(['visitor_key', 'occurred_at'], 'tracking_link_clicks_visitor_time_idx');
            $table->index(['is_bot', 'occurred_at'], 'tracking_link_clicks_bot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_link_clicks');
    }
};
