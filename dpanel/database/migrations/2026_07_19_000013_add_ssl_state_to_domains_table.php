<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('managed_domains', 'ssl_enabled')) {
            Schema::table('managed_domains', function (Blueprint $table): void {
                $table->boolean('ssl_enabled')->default(false)->after('is_active');
                $table->string('ssl_status', 32)->default('disabled')->after('ssl_enabled');
                $table->dateTime('ssl_expires_at')->nullable()->after('ssl_status');
                $table->dateTime('ssl_checked_at')->nullable()->after('ssl_expires_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('managed_domains', 'ssl_enabled')) {
            Schema::table('managed_domains', function (Blueprint $table): void {
                $table->dropColumn(['ssl_enabled', 'ssl_status', 'ssl_expires_at', 'ssl_checked_at']);
            });
        }
    }
};
