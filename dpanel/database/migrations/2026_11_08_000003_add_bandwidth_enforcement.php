<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `notify` only tells; `block` serves the account's websites a 503
        // until the month ends. Spec section 7 also names `throttle`, which
        // the gateway cannot do yet.
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->string('over_bandwidth_action', 16)->default('notify')->after('enforcement_mode');
        });

        Schema::table('users', function (Blueprint $table): void {
            // The month (`Y-m`) the bandwidth notice was sent for.
            $table->string('bandwidth_over_limit_month', 7)->nullable()->after('disk_over_limit_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('bandwidth_over_limit_month'));
        Schema::table('package_plans', fn (Blueprint $table) => $table->dropColumn('over_bandwidth_action'));
    }
};
