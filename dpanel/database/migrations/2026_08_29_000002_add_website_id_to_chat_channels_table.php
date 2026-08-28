<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->string('website_id', 64)->nullable()->after('type');
            $table->foreign('website_id')->references('id')->on('websites')->nullOnDelete();
            $table->unique('website_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->dropForeign(['website_id']);
            $table->dropUnique(['website_id']);
            $table->dropColumn('website_id');
        });
    }
};
