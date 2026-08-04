<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'manage_dns')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->boolean('manage_dns')->default(false)->after('enable_ssl');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'manage_dns')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('manage_dns');
            });
        }
    }
};
