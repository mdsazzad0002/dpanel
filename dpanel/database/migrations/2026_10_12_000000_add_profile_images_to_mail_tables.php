<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Profile photo shown as the sender's avatar in webmail.
            $table->string('avatar_path')->nullable()->after('delivery_mode');
        });
        Schema::table('mail_domains', function (Blueprint $table) {
            // SVG logo served for the domain's BIMI record.
            $table->string('bimi_logo_path')->nullable();
        });
        Schema::table('mailbox_message_metadata', function (Blueprint $table) {
            // DMARC/aligned DKIM passed, so a BIMI logo may be shown for the sender.
            $table->boolean('sender_verified')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn('avatar_path'));
        Schema::table('mail_domains', fn (Blueprint $table) => $table->dropColumn('bimi_logo_path'));
        Schema::table('mailbox_message_metadata', fn (Blueprint $table) => $table->dropColumn('sender_verified'));
    }
};
