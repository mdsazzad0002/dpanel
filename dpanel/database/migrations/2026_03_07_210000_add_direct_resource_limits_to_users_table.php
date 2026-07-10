<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('disk_space_mb_limit')->nullable()->after('package_id');
            $table->unsignedInteger('mail_accounts_limit')->nullable()->after('disk_space_mb_limit');
            $table->unsignedInteger('databases_limit')->nullable()->after('mail_accounts_limit');
            $table->unsignedInteger('bandwidth_gb_limit')->nullable()->after('databases_limit');
            $table->unsignedInteger('websites_limit')->nullable()->after('bandwidth_gb_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'disk_space_mb_limit',
                'mail_accounts_limit',
                'databases_limit',
                'bandwidth_gb_limit',
                'websites_limit',
            ]);
        });
    }
};

