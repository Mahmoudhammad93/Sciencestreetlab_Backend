<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('fulfilled_at')->nullable()->after('delivered_at');
            $table->boolean('requires_delivery_fulfillment')->default(false)->after('fulfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['fulfilled_at', 'requires_delivery_fulfillment']);
        });
    }
};
