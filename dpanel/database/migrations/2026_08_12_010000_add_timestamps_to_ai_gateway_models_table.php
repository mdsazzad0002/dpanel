<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->dropTimestamps();
        });
    }
};
