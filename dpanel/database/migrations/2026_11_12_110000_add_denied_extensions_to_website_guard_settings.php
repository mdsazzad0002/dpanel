<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comma-separated file types the gateway refuses (403): `tpl` for the
        // whole site or `/modules/*.yml` under one folder, as an app's
        // <FilesMatch> deny would on Apache.
        Schema::table('website_guard_settings', function (Blueprint $table): void {
            $table->text('denied_extensions')->nullable()->after('denied_paths');
        });
    }

    public function down(): void
    {
        Schema::table('website_guard_settings', fn (Blueprint $table) => $table->dropColumn('denied_extensions'));
    }
};
