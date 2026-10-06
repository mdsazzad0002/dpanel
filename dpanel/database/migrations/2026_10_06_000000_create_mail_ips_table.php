<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per public IP mail can leave from; each has its own hostname
        // (HELO, MX, PTR target) and certificate. Postfix reads it via MySQL.
        Schema::create('mail_ips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ip', 45)->unique();
            $table->string('hostname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('mail_domains', function (Blueprint $table) {
            $table->uuid('mail_ip_id')->nullable()->after('server_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn('mail_ip_id');
        });
        Schema::dropIfExists('mail_ips');
    }
};
