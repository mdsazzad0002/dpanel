<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website that also answers every subdomain (*.domain) through the edge
 * gateway. A subdomain with a website of its own still goes to that site.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'serve_subdomains')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->boolean('serve_subdomains')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'serve_subdomains')) {
            Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('serve_subdomains'));
        }
    }
};
