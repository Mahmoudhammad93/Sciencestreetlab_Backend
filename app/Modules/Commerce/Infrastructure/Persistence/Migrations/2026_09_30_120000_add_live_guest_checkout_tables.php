<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live guest checkout markers and secure capability/claim tables.
 * Does not alter enrollments.user_id nullability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_guest')->default(false)->after('user_id');
        });

        Schema::create('guest_order_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 20); // pay | status
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['token_hash']);
            $table->index(['order_id', 'type']);
            $table->index(['expires_at']);
        });

        Schema::create('guest_purchase_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('email');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['token_hash']);
            $table->index(['order_id']);
            $table->index(['email']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_purchase_claims');
        Schema::dropIfExists('guest_order_capabilities');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('is_guest');
        });
    }
};
