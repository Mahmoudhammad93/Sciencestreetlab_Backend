<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 64)->unique();
            $table->string('platform', 40)->index();
            $table->foreignId('campaign_id')->nullable()->constrained('social_campaigns')->restrictOnDelete();
            $table->foreignId('social_content_id')->nullable()->constrained('social_contents')->restrictOnDelete();
            $table->string('label')->nullable();
            $table->string('destination_type', 40)->index();
            $table->unsignedBigInteger('destination_id')->nullable()->index();
            $table->string('destination_path', 500);
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'status', 'expires_at'], 'tracking_links_active_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
    }
};
