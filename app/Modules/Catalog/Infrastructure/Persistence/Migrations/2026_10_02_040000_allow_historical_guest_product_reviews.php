<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical WP review prep (Option A) — mirrors historical guest orders pattern.
 *
 * LIVE REVIEW CREATION: API still requires auth:sanctum + ProductReviewService user.
 * HISTORICAL MIGRATION STORAGE: user_id may be null for guest WooCommerce reviews.
 *
 * Do NOT run on production until authorized. Local/tests only in prep phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['product_id', 'user_id']);
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->boolean('is_legacy_guest')->default(false)->after('user_id');
            $table->string('guest_display_name', 191)->nullable()->after('is_legacy_guest');
            $table->boolean('is_verified_purchase')->nullable()->after('guest_display_name');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // MySQL UNIQUE allows multiple NULL user_id values (historical guests).
            $table->unique(['product_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['product_id', 'user_id']);
            $table->dropColumn(['is_legacy_guest', 'guest_display_name', 'is_verified_purchase']);
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['product_id', 'user_id']);
        });
    }
};
