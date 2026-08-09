<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('site_owner', 64)->nullable()->after('status');
            $table->string('mail_home', 1024)->nullable()->after('site_owner');
            $table->unsignedInteger('mail_uid')->nullable()->after('mail_home');
            $table->unsignedInteger('mail_gid')->nullable()->after('mail_uid');
            $table->index(['status', 'email'], 'mailboxes_dovecot_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropIndex('mailboxes_dovecot_lookup');
            $table->dropColumn(['site_owner', 'mail_home', 'mail_uid', 'mail_gid']);
        });
    }
};
