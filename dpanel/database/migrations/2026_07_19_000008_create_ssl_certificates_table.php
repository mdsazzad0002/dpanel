<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain');
            $table->string('certificate_path')->nullable();
            $table->string('private_key_path')->nullable();
            $table->string('ca_bundle_path')->nullable();
            $table->text('certificate_pem')->nullable();
            $table->text('private_key_pem')->nullable();
            $table->string('type', 32)->default('letsencrypt');
            $table->string('challenge_type', 32)->nullable();
            $table->string('cloudflare_zone_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->datetime('issued_at')->nullable();
            $table->datetime('expires_at')->nullable();
            $table->datetime('renewed_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index('status');
            $table->index('expires_at');
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
