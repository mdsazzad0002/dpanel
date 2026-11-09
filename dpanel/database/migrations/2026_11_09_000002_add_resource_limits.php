<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog P4: a systemd slice per account. 0 means no limit, so
        // existing packages are unchanged. One core is 100%.
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_cpu_percent')->default(0)->after('max_emails_per_day');
            $table->unsignedInteger('max_memory_mb')->default(0)->after('max_cpu_percent');
            $table->unsignedInteger('max_processes')->default(0)->after('max_memory_mb');
        });

        Schema::table('users', function (Blueprint $table): void {
            // What drust was last given (a hash), so an unchanged limit is not
            // sent again and a removed one is taken away once.
            $table->string('resource_limits_applied', 64)->nullable()->after('bandwidth_over_limit_month');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('resource_limits_applied'));
        Schema::table('package_plans', fn (Blueprint $table) => $table->dropColumn(['max_cpu_percent', 'max_memory_mb', 'max_processes']));
    }
};
