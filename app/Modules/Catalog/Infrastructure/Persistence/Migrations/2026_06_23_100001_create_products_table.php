<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sku', 100)->unique();
            $table->string('slug')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->decimal('price', 12, 2);
            $table->decimal('compare_price', 12, 2)->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->integer('stock_quantity')->nullable();
            $table->boolean('manage_stock')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedBigInteger('course_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('name')->nullable();
            $table->json('short_description')->nullable();
            $table->json('description')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
