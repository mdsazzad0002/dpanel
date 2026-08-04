<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index()->after('assigned_reseller_id');
            $table->unsignedBigInteger('owner_user_id')->nullable()->index()->after('created_by_user_id');
            $table->unsignedBigInteger('transferred_by_user_id')->nullable()->index()->after('owner_user_id');
            $table->timestamp('transferred_at')->nullable()->after('transferred_by_user_id');
        });

        $adminId = DB::table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->orderBy('users.id')
            ->value('users.id');

        DB::table('dns_zones')->orderBy('id')->each(function ($zone) use ($adminId) {
            $ownerId = $zone->assigned_user_id ?: $zone->assigned_reseller_id ?: $adminId;
            DB::table('dns_zones')->where('id', $zone->id)->update([
                'created_by_user_id' => $ownerId,
                'owner_user_id' => $ownerId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropColumn(['created_by_user_id', 'owner_user_id', 'transferred_by_user_id', 'transferred_at']);
        });
    }
};
