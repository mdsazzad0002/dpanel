<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port shares can point at other servers: one remote backend (reverse proxy)
 * or several (load balancer). drust reads these columns; a share stays
 * "local" (127.0.0.1:target_port) unless target_type is "remote".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('port_shares', function (Blueprint $table): void {
            $table->unsignedInteger('target_port')->nullable()->change();
            $table->string('target_type', 10)->default('local')->after('target_port');
            // Space-separated base URLs, e.g. "http://192.168.0.106:80 http://192.168.0.107:80".
            $table->text('targets')->nullable()->after('target_type');
            $table->string('lb_policy', 16)->default('round_robin')->after('targets');
            $table->boolean('preserve_host')->default(true)->after('lb_policy');
            $table->boolean('verify_tls')->default(true)->after('preserve_host');
            $table->unsignedSmallInteger('timeout_seconds')->default(30)->after('verify_tls');
        });
    }

    public function down(): void
    {
        Schema::table('port_shares', function (Blueprint $table): void {
            $table->dropColumn(['target_type', 'targets', 'lb_policy', 'preserve_host', 'verify_tls', 'timeout_seconds']);
        });
    }
};
