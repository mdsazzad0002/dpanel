<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->dropForeign(['provider_id']);
        });

        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->unsignedBigInteger('provider_id')->nullable()->change();
            $table->foreign('provider_id')->references('id')->on('ai_gateway_providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->dropForeign(['provider_id']);
        });

        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
            $table->foreign('provider_id')->references('id')->on('ai_gateway_providers')->cascadeOnDelete();
        });
    }
};
