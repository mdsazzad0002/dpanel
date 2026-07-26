<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mailboxes', 'mail_domain_id')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->uuid('mail_domain_id')->nullable()->after('id');
            });
        }

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreign('mail_domain_id')->references('id')->on('mail_domains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropForeign(['mail_domain_id']);
            $table->dropColumn('mail_domain_id');
        });
    }
};
