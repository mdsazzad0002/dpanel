<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read by the edge gateway (drust/src/edge_gateway/source.rs) on every
        // reload; keep column names in step with CACHE_SETTINGS_SQL there.
        Schema::create('website_edge_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('website_id', 64)->unique();
            $table->string('mode', 16)->default('off');
            $table->unsignedInteger('edge_ttl')->default(3600);
            $table->unsignedInteger('browser_ttl')->nullable();
            $table->text('bypass_paths')->nullable();
            $table->text('bypass_cookies')->nullable();
            $table->boolean('ignore_query_string')->default(false);
            $table->boolean('serve_stale')->default(true);
            // Unix seconds, so the gateway needs no time zone to compare it.
            $table->unsignedBigInteger('development_mode_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_edge_cache');
    }
};
