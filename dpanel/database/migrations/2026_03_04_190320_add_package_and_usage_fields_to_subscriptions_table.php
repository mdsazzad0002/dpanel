<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('used_mail_accounts')->default(0)->after('ends_at');
            $table->unsignedBigInteger('used_disk_space_mb')->default(0)->after('used_mail_accounts');
            $table->unsignedInteger('used_databases')->default(0)->after('used_disk_space_mb');
            $table->unsignedBigInteger('used_files')->default(0)->after('used_databases');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn([
                'used_mail_accounts',
                'used_disk_space_mb',
                'used_databases',
                'used_files',
            ]);
        });
    }
};
