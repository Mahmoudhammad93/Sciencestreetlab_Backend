<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('enrollment_verification_token', 64)->nullable()->unique();
        });

        DB::table('enrollments')
            ->whereNull('enrollment_verification_token')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('enrollments')->where('id', $row->id)->update([
                        'enrollment_verification_token' => bin2hex(random_bytes(32)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropUnique(['enrollment_verification_token']);
            $table->dropColumn('enrollment_verification_token');
        });
    }
};
