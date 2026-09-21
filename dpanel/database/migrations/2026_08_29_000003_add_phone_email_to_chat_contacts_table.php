<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('username');
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'email']);
        });
    }
};
