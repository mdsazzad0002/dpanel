<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username');
            $table->string('host')->default('localhost');
            $table->text('password_hash');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['username', 'host']);
            $table->index('server_id');
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_users');
    }
};
