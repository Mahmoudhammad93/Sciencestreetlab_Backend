<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('fingerprint', 64)->unique();
            $table->string('level', 16)->default('error');
            $table->string('exception_class', 255);
            $table->text('message');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('module', 64)->nullable();
            $table->string('source', 32)->default('http');
            $table->string('application_class', 255)->nullable();
            $table->string('application_method', 128)->nullable();
            $table->string('eloquent_model', 128)->nullable();
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('route_name', 255)->nullable();
            $table->string('request_method', 16)->nullable();
            $table->string('request_path', 512)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type', 16)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->json('context')->nullable();
            $table->text('trace')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('resolved_at');
            $table->index('module');
            $table->index('user_id');
            $table->index('level');
            $table->index('request_id');
            $table->index('exception_class');
            $table->index('source');
            $table->index('user_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_incidents');
    }
};
