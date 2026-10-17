<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether www.<domain> is served, covered by SSL and given a DNS
        // record alongside a main domain. On by default, as it always was.
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'include_www')) {
                $table->boolean('include_www')->default(true)->after('manage_dns');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'include_www')) {
                $table->dropColumn('include_www');
            }
        });
    }
};
