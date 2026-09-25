<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'wordpress_db_prefix')) {
                $table->string('wordpress_db_prefix')->nullable()->after('ssl_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'wordpress_db_prefix')) {
                $table->dropColumn('wordpress_db_prefix');
            }
        });
    }
};
