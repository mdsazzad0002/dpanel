<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain')->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->boolean('enable_dkim')->default(true);
            $table->boolean('enable_spf')->default(true);
            $table->boolean('enable_dmarc')->default(true);
            $table->string('dkim_selector', 32)->default('default');
            $table->text('dkim_private_key')->nullable();
            $table->text('dkim_public_key')->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index('server_id');
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_domains');
    }
};
