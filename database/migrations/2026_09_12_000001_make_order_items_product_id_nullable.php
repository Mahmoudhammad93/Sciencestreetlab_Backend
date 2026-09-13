<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products');
        });
    }
};
