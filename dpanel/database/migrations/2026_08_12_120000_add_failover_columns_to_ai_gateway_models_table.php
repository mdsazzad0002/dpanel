<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->unsignedInteger('failure_count')->default(0)->after('is_active');
            $table->timestamp('cooldown_until')->nullable()->after('failure_count');
        });
    }

    public function down(): void
    {
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->dropColumn(['failure_count', 'cooldown_until']);
        });
    }
};
