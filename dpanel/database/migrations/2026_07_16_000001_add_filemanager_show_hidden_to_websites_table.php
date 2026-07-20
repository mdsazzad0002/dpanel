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
        if (! Schema::hasColumn('websites', 'filemanager_show_hidden')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->boolean('filemanager_show_hidden')->default(false)->after('enable_ssl');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('websites', 'filemanager_show_hidden')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('filemanager_show_hidden');
            });
        }
    }
};
