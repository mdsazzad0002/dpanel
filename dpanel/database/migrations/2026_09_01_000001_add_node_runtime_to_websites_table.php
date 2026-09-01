<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'runtime')) {
                $table->string('runtime')->default('php')->after('php_version');
            }
            if (! Schema::hasColumn('websites', 'node_entry_file')) {
                $table->string('node_entry_file')->nullable()->after('runtime');
            }
            if (! Schema::hasColumn('websites', 'node_port')) {
                $table->unsignedInteger('node_port')->nullable()->after('node_entry_file');
            }
            if (! Schema::hasColumn('websites', 'node_version')) {
                $table->string('node_version')->nullable()->after('node_port');
            }
            if (! Schema::hasColumn('websites', 'node_start_command')) {
                $table->string('node_start_command')->nullable()->after('node_version');
            }
            if (! Schema::hasColumn('websites', 'node_process_status')) {
                $table->string('node_process_status')->nullable()->after('node_start_command');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['runtime', 'node_entry_file', 'node_port', 'node_version', 'node_start_command', 'node_process_status'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
