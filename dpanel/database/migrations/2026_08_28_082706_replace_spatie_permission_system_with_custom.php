<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the Spatie laravel-permission tables with a plain `role`
     * string column on users (backfilled from model_has_roles) and drops
     * the pivot/permission tables Spatie owned. roles.permissions_csv
     * (added in the previous migration) is now the sole permission store.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->nullable()->after('reseller_id')->index();
            });
        }

        if (Schema::hasTable('model_has_roles') && Schema::hasTable('roles')) {
            $assignments = DB::table('model_has_roles')
                ->where('model_type', \App\Models\User::class)
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->select('model_has_roles.model_id as user_id', 'roles.name as role_name')
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('users')
                    ->where('id', $assignment->user_id)
                    ->update(['role' => $assignment->role_name]);
            }
        }

        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('permissions');

        if (Schema::hasColumn('roles', 'guard_name')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_guard_name_unique');
                $table->dropColumn('guard_name');
                $table->unique('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Note: this only restores the `users.role` column shape. The dropped
     * Spatie pivot/permission tables are not recreated or repopulated.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
