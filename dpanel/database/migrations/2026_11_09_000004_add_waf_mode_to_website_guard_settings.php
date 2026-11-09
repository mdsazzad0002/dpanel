<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog S2: `off`, `detect` (matches only logged) or `block`. A site
        // without a row is `detect` too; the gateway reads it with COALESCE.
        Schema::table('website_guard_settings', function (Blueprint $table): void {
            $table->string('waf_mode', 8)->default('detect')->after('cloudflare_only');
        });
    }

    public function down(): void
    {
        Schema::table('website_guard_settings', fn (Blueprint $table) => $table->dropColumn('waf_mode'));
    }
};
