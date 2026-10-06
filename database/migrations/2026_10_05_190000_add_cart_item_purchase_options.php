<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart-line purchase options (microscope book_language) + identity key.
 * order_items.metadata already exists — no order DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cart_items', 'metadata')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->json('metadata')->nullable();
            });
        }

        if (! Schema::hasColumn('cart_items', 'options_key')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->string('options_key', 64)->default('');
            });
        }

        $indexes = collect(Schema::getIndexes('cart_items'));
        $hasLegacy = $indexes->contains(
            fn (array $index): bool => ($index['name'] ?? '') === 'cart_items_cart_id_product_id_unique'
        );
        $hasOptions = $indexes->contains(
            fn (array $index): bool => ($index['name'] ?? '') === 'cart_items_cart_product_options_unique'
        );

        if (! $hasLegacy && $hasOptions) {
            return;
        }

        Schema::table('cart_items', function (Blueprint $table) use ($hasLegacy, $hasOptions): void {
            // cart_id FK may be using the legacy unique's left-most prefix.
            $table->dropForeign(['cart_id']);

            if ($hasLegacy) {
                $table->dropUnique('cart_items_cart_id_product_id_unique');
            }

            if (! $hasOptions) {
                $table->unique(
                    ['cart_id', 'product_id', 'options_key'],
                    'cart_items_cart_product_options_unique'
                );
            }

            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('cart_items'));
        $hasOptions = $indexes->contains(
            fn (array $index): bool => ($index['name'] ?? '') === 'cart_items_cart_product_options_unique'
        );
        $hasLegacy = $indexes->contains(
            fn (array $index): bool => ($index['name'] ?? '') === 'cart_items_cart_id_product_id_unique'
        );

        Schema::table('cart_items', function (Blueprint $table) use ($hasOptions, $hasLegacy): void {
            $table->dropForeign(['cart_id']);

            if ($hasOptions) {
                $table->dropUnique('cart_items_cart_product_options_unique');
            }

            if (! $hasLegacy) {
                $table->unique(['cart_id', 'product_id']);
            }

            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            if (Schema::hasColumn('cart_items', 'options_key')) {
                $table->dropColumn('options_key');
            }
            if (Schema::hasColumn('cart_items', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
