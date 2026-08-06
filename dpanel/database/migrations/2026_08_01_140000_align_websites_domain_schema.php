<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySql = DB::getDriverName() === 'mysql';
        if ($isMySql) {
            DB::statement("ALTER TABLE websites MODIFY type VARCHAR(32) NULL");
        }
        DB::table('websites')->where('type', 'alis')->update(['type' => 'alias']);
        DB::table('websites')->where('type', 'sub')->update(['type' => 'subdomain']);
        DB::table('websites')->whereNotIn('type', ['primary', 'alias', 'subdomain', 'redirect'])->update(['type' => 'primary']);
        if ($isMySql) {
            DB::statement("ALTER TABLE websites MODIFY type ENUM('primary', 'alias', 'subdomain', 'redirect') NOT NULL DEFAULT 'primary'");
        }

        if (! Schema::hasColumn('websites', 'hostname')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('hostname')->nullable()->after('domain');
            });
        }

        if (! Schema::hasColumn('websites', 'scope')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('scope', 32)->nullable()->after('hostname');
            });
        }

        if (! Schema::hasColumn('websites', 'parent_id')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('parent_id', 64)->nullable()->after('scope');
            });
        }

        if (! Schema::hasColumn('websites', 'config_source_id')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('config_source_id', 64)->nullable()->after('parent_id');
            });
        }

        if (! Schema::hasColumn('websites', 'ssl_mode')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->enum('ssl_mode', ['none', 'letsencrypt', 'custom'])->default('none')->after('enable_ssl');
            });
        }

        if (Schema::hasColumn('websites', 'hostname')) {
            DB::statement('UPDATE websites SET hostname = domain WHERE hostname IS NULL');
        }

        if (Schema::hasColumn('websites', 'ssl_mode')) {
            DB::table('websites')->whereNull('ssl_mode')->update([
                'ssl_mode' => DB::raw("CASE WHEN enable_ssl = 1 THEN 'letsencrypt' ELSE 'none' END"),
            ]);
        }

        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'parent_id')) {
                $table->foreign('parent_id')->references('id')->on('websites')->nullOnDelete();
            }

            if (Schema::hasColumn('websites', 'scope')) {
                $table->index(['scope', 'status']);
            }

            if (Schema::hasColumn('websites', 'config_source_id')) {
                $table->index('config_source_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'parent_id')) {
                $table->dropForeign(['parent_id']);
            }

            if (Schema::hasColumn('websites', 'scope')) {
                $table->dropIndex(['scope', 'status']);
            }

            if (Schema::hasColumn('websites', 'config_source_id')) {
                $table->dropIndex(['config_source_id']);
            }

            if (Schema::hasColumn('websites', 'hostname')) {
                $table->dropUnique(['hostname']);
            }

            $dropColumns = array_values(array_filter([
                Schema::hasColumn('websites', 'hostname') ? 'hostname' : null,
                Schema::hasColumn('websites', 'scope') ? 'scope' : null,
                Schema::hasColumn('websites', 'parent_id') ? 'parent_id' : null,
                Schema::hasColumn('websites', 'config_source_id') ? 'config_source_id' : null,
                Schema::hasColumn('websites', 'client_max_body_size') ? 'client_max_body_size' : null,
                Schema::hasColumn('websites', 'ssl_mode') ? 'ssl_mode' : null,
            ]));

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
