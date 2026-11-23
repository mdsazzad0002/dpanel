<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-key request limits. NULL = use the config default
     * (aigateway.rate_limit.*), 0 = no limit for that bucket.
     */
    public function up(): void
    {
        Schema::table('ai_gateway_api_keys', function (Blueprint $table): void {
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('is_active');
            $table->unsignedInteger('ip_rate_limit_per_minute')->nullable()->after('rate_limit_per_minute');
            $table->text('allowed_ips')->nullable()->after('ip_rate_limit_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('ai_gateway_api_keys', function (Blueprint $table): void {
            $table->dropColumn(['rate_limit_per_minute', 'ip_rate_limit_per_minute', 'allowed_ips']);
        });
    }
};
