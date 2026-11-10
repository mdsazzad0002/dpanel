<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog M5: an uploaded certificate is never renewed, so its owner is
        // warned 30, 7 and 1 days before it ends and when it has ended. This
        // holds the last of those that was sent, so each goes out once.
        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('expiry_alert_days')->nullable()->after('auto_renew');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', fn (Blueprint $table) => $table->dropColumn('expiry_alert_days'));
    }
};
