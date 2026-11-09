<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog P5: messages an account may send, counted per recipient
        // by Rspamd. 0 means no limit, so existing packages are unchanged.
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_emails_per_hour')->default(0)->after('max_bandwidth_gb');
            $table->unsignedInteger('max_emails_per_day')->default(0)->after('max_emails_per_hour');
        });
    }

    public function down(): void
    {
        Schema::table('package_plans', fn (Blueprint $table) => $table->dropColumn(['max_emails_per_hour', 'max_emails_per_day']));
    }
};
