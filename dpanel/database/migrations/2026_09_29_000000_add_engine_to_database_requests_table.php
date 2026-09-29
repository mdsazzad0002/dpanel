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
        Schema::table('database_requests', function (Blueprint $table) {
            // Existing rows were all created on MySQL/MariaDB.
            $table->string('engine', 20)->default('mariadb')->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_requests', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
