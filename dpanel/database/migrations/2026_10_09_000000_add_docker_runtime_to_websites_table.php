<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'docker_image')) {
                $table->string('docker_image')->nullable()->after('python_process_status');
            }
            if (! Schema::hasColumn('websites', 'docker_port')) {
                // Host port, always bound to 127.0.0.1; the edge gateway proxies the domain to it.
                $table->unsignedInteger('docker_port')->nullable()->after('docker_image');
            }
            if (! Schema::hasColumn('websites', 'docker_container_port')) {
                $table->unsignedInteger('docker_container_port')->nullable()->after('docker_port');
            }
            if (! Schema::hasColumn('websites', 'docker_mount_target')) {
                $table->string('docker_mount_target')->nullable()->after('docker_container_port');
            }
            if (! Schema::hasColumn('websites', 'docker_env')) {
                $table->text('docker_env')->nullable()->after('docker_mount_target');
            }
            if (! Schema::hasColumn('websites', 'docker_public')) {
                $table->boolean('docker_public')->default(false)->after('docker_env');
            }
            if (! Schema::hasColumn('websites', 'docker_process_status')) {
                $table->string('docker_process_status')->nullable()->after('docker_public');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['docker_image', 'docker_port', 'docker_container_port', 'docker_mount_target', 'docker_env', 'docker_public', 'docker_process_status'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
