<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('ai_gateway_providers', 'base_url')) {
            return;
        }

        Schema::table('ai_gateway_providers', function (Blueprint $table): void {
            $table->string('base_url')->nullable()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('ai_gateway_providers', 'base_url')) {
            return;
        }

        Schema::table('ai_gateway_providers', function (Blueprint $table): void {
            $table->dropColumn('base_url');
        });
    }
};
