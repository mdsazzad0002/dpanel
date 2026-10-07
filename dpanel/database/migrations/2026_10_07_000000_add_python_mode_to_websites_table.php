<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'python_mode')) {
                $table->string('python_mode', 20)->nullable()->after('python_workers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'python_mode')) {
                $table->dropColumn('python_mode');
            }
        });
    }
};
