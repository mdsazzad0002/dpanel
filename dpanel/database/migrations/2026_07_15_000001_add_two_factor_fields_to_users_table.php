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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('two_factor_enabled')->default(false)->after('remember_token');
            $table->string('two_factor_method', 32)->nullable()->after('two_factor_enabled');
            $table->string('two_factor_secret', 255)->nullable()->after('two_factor_method');
            $table->string('two_factor_telegram_chat_id', 255)->nullable()->after('two_factor_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_enabled',
                'two_factor_method',
                'two_factor_secret',
                'two_factor_telegram_chat_id',
            ]);
        });
    }
};
