<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tasks S5 and S6. The edge gateway reads both; an alias uses its main site's rows.
        Schema::create('website_protected_directories', function (Blueprint $table): void {
            $table->id();
            $table->string('website_id', 64);
            $table->string('path', 255);
            $table->string('realm', 80)->nullable();
            $table->string('username', 64);
            // s256i$<rounds>$<salt hex>$<digest hex>; see App\Support\BasicAuthHash.
            $table->string('password_hash', 191);
            $table->timestamps();

            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
            $table->unique(['website_id', 'path', 'username']);
        });

        Schema::create('website_guard_settings', function (Blueprint $table): void {
            $table->string('website_id', 64)->primary();
            // Comma-separated path prefixes where PHP files are refused.
            $table->text('no_php_paths')->nullable();
            $table->boolean('hotlink_protection')->default(false);
            // Comma-separated hosts besides the site's own that may embed its media.
            $table->text('hotlink_allowed_hosts')->nullable();
            // Refuse connections that do not come from Cloudflare's network.
            $table->boolean('cloudflare_only')->default(false);
            $table->timestamps();

            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_guard_settings');
        Schema::dropIfExists('website_protected_directories');
    }
};
