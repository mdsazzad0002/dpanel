<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('serving_state', 20)->default('active');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('serving_state');
        });
    }
};
