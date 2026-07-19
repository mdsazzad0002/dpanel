<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain')->unique();
            $table->string('server_id', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
