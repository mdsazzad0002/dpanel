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
        if (! Schema::hasColumn('websites', 'wordpress_db_prefix')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('wordpress_db_prefix', 32)->nullable()->after('wordpress_version');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('websites', 'wordpress_db_prefix')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('wordpress_db_prefix');
            });
        }
    }
};
