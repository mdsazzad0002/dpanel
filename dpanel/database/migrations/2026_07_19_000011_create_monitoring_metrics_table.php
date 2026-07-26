<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('server_id');
            $table->string('metric_type', 64);
            $table->float('value');
            $table->string('unit', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'metric_type']);
            $table->index(['server_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_metrics');
    }
};
