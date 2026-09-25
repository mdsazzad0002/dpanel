<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'python_entry_file')) {
                $table->string('python_entry_file')->nullable()->after('node_process_status');
            }
            if (! Schema::hasColumn('websites', 'python_port')) {
                $table->unsignedInteger('python_port')->nullable()->after('python_entry_file');
            }
            if (! Schema::hasColumn('websites', 'python_version')) {
                $table->string('python_version')->nullable()->after('python_port');
            }
            if (! Schema::hasColumn('websites', 'python_start_command')) {
                $table->string('python_start_command')->nullable()->after('python_version');
            }
            if (! Schema::hasColumn('websites', 'python_process_status')) {
                $table->string('python_process_status')->nullable()->after('python_start_command');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['python_entry_file', 'python_port', 'python_version', 'python_start_command', 'python_process_status'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
