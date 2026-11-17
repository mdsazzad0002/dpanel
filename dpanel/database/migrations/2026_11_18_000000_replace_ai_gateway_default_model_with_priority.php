<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Gateway no longer has a "default model": the router picks the best
 * working model by priority (provider first, then model) and health. Models
 * get a priority; the provider's default_model and the model's is_default go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_gateway_models', 'priority')) {
            Schema::table('ai_gateway_models', function (Blueprint $table): void {
                $table->unsignedSmallInteger('priority')->default(0)->after('is_active');
            });
        }

        // The old default keeps first place within its provider.
        if (Schema::hasColumn('ai_gateway_models', 'is_default')) {
            DB::table('ai_gateway_models')->where('is_default', true)->update(['priority' => 10]);

            Schema::table('ai_gateway_models', function (Blueprint $table): void {
                $table->dropColumn('is_default');
            });
        }

        if (Schema::hasColumn('ai_gateway_providers', 'default_model')) {
            Schema::table('ai_gateway_providers', function (Blueprint $table): void {
                $table->dropColumn('default_model');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ai_gateway_providers', function (Blueprint $table): void {
            $table->string('default_model')->nullable();
        });
        Schema::table('ai_gateway_models', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
            $table->dropColumn('priority');
        });
    }
};
