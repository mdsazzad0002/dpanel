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
        if (! Schema::hasColumn('websites', 'root_path')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->string('root_path')->nullable()->after('domain');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('websites', 'root_path')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('root_path');
            });
        }
    }
};
