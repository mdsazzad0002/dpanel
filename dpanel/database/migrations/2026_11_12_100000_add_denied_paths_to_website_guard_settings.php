<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comma-separated path prefixes the gateway refuses outright (403):
        // an app's private folders, which its .htaccess would deny on Apache.
        Schema::table('website_guard_settings', function (Blueprint $table): void {
            $table->text('denied_paths')->nullable()->after('no_php_paths');
        });
    }

    public function down(): void
    {
        Schema::table('website_guard_settings', fn (Blueprint $table) => $table->dropColumn('denied_paths'));
    }
};
