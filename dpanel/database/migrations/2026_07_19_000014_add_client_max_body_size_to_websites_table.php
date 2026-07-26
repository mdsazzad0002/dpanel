<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'client_max_body_size')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('client_max_body_size', 16)->default('2G')->after('php_version');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'client_max_body_size')) {
            Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('client_max_body_size'));
        }
    }
};
