<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_memories', function (Blueprint $table): void {
            $table->unsignedTinyInteger('confidence')->default(60)->after('priority');
            $table->unsignedInteger('fail_count')->default(0)->after('success_count');
        });
    }

    public function down(): void
    {
        Schema::table('ai_memories', function (Blueprint $table): void {
            $table->dropColumn(['confidence', 'fail_count']);
        });
    }
};

