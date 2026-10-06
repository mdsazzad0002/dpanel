<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'python_workers')) {
                $table->unsignedSmallInteger('python_workers')->nullable()->after('python_start_command');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'python_workers')) {
                $table->dropColumn('python_workers');
            }
        });
    }
};
