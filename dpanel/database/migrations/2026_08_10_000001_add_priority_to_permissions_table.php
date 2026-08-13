<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('permission.table_names.permissions', 'permissions');

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority')->default(1)->after('guard_name')->index();
        });
    }

    public function down(): void
    {
        $tableName = config('permission.table_names.permissions', 'permissions');

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
