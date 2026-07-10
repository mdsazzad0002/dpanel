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
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
