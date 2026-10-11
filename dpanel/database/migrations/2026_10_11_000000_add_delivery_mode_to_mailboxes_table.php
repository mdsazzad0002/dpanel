<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // 'live' delivers anywhere; 'sandbox' (test) keeps mail on this
            // server. Postfix reads it, see panel_configure_postfix_sql.
            $table->string('delivery_mode', 16)->default('live')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('delivery_mode');
        });
    }
};
