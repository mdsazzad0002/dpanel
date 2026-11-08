<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog M1-M3. Postfix's alias query reads mail_forwarders and
 * mail_domains.catch_all_to directly; Dovecot runs each mailbox's Sieve
 * reply, which drust writes from mailbox_autoresponders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_forwarders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('address')->unique();
            $table->string('domain')->index();
            // Comma-separated, as Postfix wants an alias result.
            $table->text('destinations');
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('mail_domains', function (Blueprint $table) {
            $table->string('catch_all_to')->nullable()->after('status');
        });

        Schema::create('mailbox_autoresponders', function (Blueprint $table) {
            $table->id();
            $table->uuid('mailbox_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('subject', 200);
            $table->text('body');
            $table->unsignedSmallInteger('interval_days')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_autoresponders');
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn('catch_all_to');
        });
        Schema::dropIfExists('mail_forwarders');
    }
};
