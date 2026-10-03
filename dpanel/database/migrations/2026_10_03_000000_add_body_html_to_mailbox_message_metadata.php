<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanitized HTML body; '' means the message has no HTML part and
        // null means the body was cached before HTML was kept.
        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            $table->mediumText('body_html')->nullable()->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            $table->dropColumn('body_html');
        });
    }
};
