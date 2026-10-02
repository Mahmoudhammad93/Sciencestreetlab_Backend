<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed shipping rates + product free-shipping flag + order rate snapshot.
 *
 * PRODUCTION: do not run until explicitly authorized (DDL_REQUIRED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('free_shipping')->default(false)->after('is_featured');
        });

        Schema::create('shipping_rate_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->json('name');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('shipping_rate_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_rate_group_id')
                ->constrained('shipping_rate_groups')
                ->cascadeOnDelete();
            /** city | district | zone */
            $table->string('scope_type', 20);
            $table->string('bosta_location_id', 64);
            $table->string('bosta_location_name', 191)->nullable();
            $table->string('bosta_location_name_ar', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['scope_type', 'bosta_location_id'], 'shipping_rate_locations_scope_loc_unique');
            $table->index(['shipping_rate_group_id', 'is_active']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->json('shipping_snapshot')->nullable()->after('shipping_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('shipping_snapshot');
        });

        Schema::dropIfExists('shipping_rate_locations');
        Schema::dropIfExists('shipping_rate_groups');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('free_shipping');
        });
    }
};
