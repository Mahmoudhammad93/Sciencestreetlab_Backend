<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_contents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->nullable()->constrained('social_campaigns')->restrictOnDelete();
            $table->string('platform', 40)->index();
            $table->string('content_type', 40)->index();
            $table->string('external_content_id')->nullable()->index();
            $table->string('title');
            $table->string('external_url', 2048)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('destination_type', 40)->nullable()->index();
            $table->unsignedBigInteger('destination_id')->nullable()->index();
            $table->string('destination_path', 500);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['platform', 'content_type', 'external_content_id'], 'social_contents_ext_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_contents');
    }
};
