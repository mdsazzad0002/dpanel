<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_ai_replies')->nullable()->after('max_bandwidth_gb');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedInteger('ai_replies_used')->default(0)->after('reply_language');
        });
    }

    public function down(): void
    {
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->dropColumn('max_ai_replies');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('ai_replies_used');
        });
    }
};
