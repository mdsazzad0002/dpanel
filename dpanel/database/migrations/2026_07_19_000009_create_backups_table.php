<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 32);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('storage_type', 32)->default('local');
            $table->string('storage_path')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_message')->nullable();
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->datetime('expires_at')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('server_id');
            $table->index('source_type');
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
