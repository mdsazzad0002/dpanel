<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('database_user_privileges')) {
            if (DB::table('database_user_privileges')->count() > 0) {
                throw new \RuntimeException('database_user_privileges contains data but its migration is not registered.');
            }
            Schema::drop('database_user_privileges');
        }

        Schema::create('database_user_privileges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('database_user_id');
            $table->string('database_name');
            $table->json('privileges');
            $table->timestamps();

            $table->foreign('database_user_id')->references('id')->on('database_users')->cascadeOnDelete();
            $table->unique(['database_user_id', 'database_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_user_privileges');
    }
};
