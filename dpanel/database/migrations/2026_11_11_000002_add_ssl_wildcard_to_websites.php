<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog M4: the site's certificate also covers *.<domain>, proved
        // with a DNS-01 TXT record in its zone on PowerDNS or Cloudflare.
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('ssl_wildcard')->default(false)->after('enable_ssl');
        });
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('ssl_wildcard'));
    }
};
