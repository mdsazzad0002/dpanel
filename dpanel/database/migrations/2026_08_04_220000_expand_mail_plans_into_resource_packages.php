<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_websites')->default(1)->after('max_mailboxes');
            $table->unsignedInteger('max_databases')->default(1)->after('max_websites');
            $table->unsignedInteger('max_bandwidth_gb')->default(10)->after('max_databases');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('package_id')->nullable()->after('reseller_id')
                ->constrained('mail_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('package_id');
        });
        Schema::table('mail_plans', function (Blueprint $table): void {
            $table->dropColumn(['max_websites', 'max_databases', 'max_bandwidth_gb']);
        });
    }
};
