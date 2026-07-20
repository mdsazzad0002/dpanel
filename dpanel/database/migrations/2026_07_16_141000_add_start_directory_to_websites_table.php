<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'start_directory')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('start_directory')->nullable()->after('project_root');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'start_directory')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('start_directory');
            });
        }
    }
};
