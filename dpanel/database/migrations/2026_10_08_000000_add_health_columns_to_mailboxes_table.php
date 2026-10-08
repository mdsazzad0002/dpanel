<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Why the delivery check set status to 'unhealthy'.
            $table->text('health_error')->nullable()->after('status');
            $table->timestamp('health_checked_at')->nullable()->after('health_error');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['health_error', 'health_checked_at']);
        });
    }
};
