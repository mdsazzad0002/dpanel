<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE websites MODIFY type VARCHAR(32) NULL");
        DB::table('websites')->where('type', 'alis')->update(['type' => 'alias']);
        DB::table('websites')->where('type', 'sub')->update(['type' => 'subdomain']);
        DB::table('websites')->whereNotIn('type', ['primary', 'alias', 'subdomain', 'redirect'])->update(['type' => 'primary']);
        DB::statement("ALTER TABLE websites MODIFY type ENUM('primary', 'alias', 'subdomain', 'redirect') NOT NULL DEFAULT 'primary'");

        if (! Schema::hasColumn('websites', 'ssl_mode')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->enum('ssl_mode', ['none', 'letsencrypt', 'custom'])->default('none')->after('enable_ssl');
            });
        }

        DB::table('websites')->whereNull('hostname')->update(['hostname' => DB::raw('domain')]);
        DB::table('websites')->whereNull('ssl_mode')->update([
            'ssl_mode' => DB::raw("CASE WHEN enable_ssl = 1 THEN 'letsencrypt' ELSE 'none' END"),
        ]);

        Schema::table('websites', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('websites')->nullOnDelete();
            $table->index(['scope', 'status']);
            $table->index('config_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['scope', 'status']);
            $table->dropIndex(['config_source_id']);
            $table->dropUnique(['hostname']);
            $table->dropColumn(['hostname', 'scope', 'parent_id', 'config_source_id', 'client_max_body_size', 'ssl_mode']);
        });
    }
};
