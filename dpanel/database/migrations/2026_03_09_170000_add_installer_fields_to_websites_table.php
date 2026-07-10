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
        if (! Schema::hasColumn('websites', 'app_installer')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('app_installer', 32)->default('none')->after('php_version');
            });
        }

        if (! Schema::hasColumn('websites', 'wordpress_version')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('wordpress_version', 20)->default('latest')->after('app_installer');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('websites', 'wordpress_version')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('wordpress_version');
            });
        }

        if (Schema::hasColumn('websites', 'app_installer')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('app_installer');
            });
        }
    }
};
