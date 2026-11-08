<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A terminated account stays suspended until purge_after, then its
        // websites are archived to the trash and everything is deleted.
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terminated_at')->nullable()->after('suspended_with_reseller');
            $table->timestamp('purge_after')->nullable()->after('terminated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['purge_after']);
            $table->dropColumn(['terminated_at', 'purge_after']);
        });
    }
};
