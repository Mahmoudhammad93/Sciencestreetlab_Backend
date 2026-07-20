<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->after('id')->unique()->nullable();
            $table->string('phone', 20)->after('email')->nullable()->unique();
            $table->string('locale', 10)->after('password')->default('ar');
            $table->string('timezone', 50)->after('locale')->default('Africa/Cairo');
            $table->string('avatar_path', 500)->after('timezone')->nullable();
            $table->foreignId('school_id')->after('avatar_path')->nullable();
            $table->boolean('is_active')->after('school_id')->default(true);
            $table->timestamp('phone_verified_at')->after('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'uuid', 'phone', 'locale', 'timezone', 'avatar_path',
                'school_id', 'is_active', 'phone_verified_at', 'last_login_at',
            ]);
        });
    }
};
