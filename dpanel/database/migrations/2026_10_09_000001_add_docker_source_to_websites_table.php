<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'docker_source')) {
                // 'image' (null): the panel runs the site's own container. 'port': the
                // domain is proxied to a port something else already serves, such as a stack.
                $table->string('docker_source', 16)->nullable()->after('docker_process_status');
            }
            if (! Schema::hasColumn('websites', 'docker_stack')) {
                $table->string('docker_stack', 63)->nullable()->after('docker_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['docker_source', 'docker_stack'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
