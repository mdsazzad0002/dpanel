<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('server_id');
            $table->string('service_name', 128);
            $table->string('display_name', 128)->nullable();
            $table->string('status', 32)->default('unknown');
            $table->boolean('is_enabled')->default(true);
            $table->text('details')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'service_name']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_status');
    }
};
