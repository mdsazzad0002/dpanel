<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why the last background start failed (e.g. pip install output),
        // shown on the project card.
        Schema::table('app_projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_projects', 'last_error')) {
                $table->text('last_error')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_projects', function (Blueprint $table): void {
            if (Schema::hasColumn('app_projects', 'last_error')) {
                $table->dropColumn('last_error');
            }
        });
    }
};
