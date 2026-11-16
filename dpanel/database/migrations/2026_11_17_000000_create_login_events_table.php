<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            // panel: someone signing in to dPanel; ssh: a Linux account over SSH.
            $table->string('channel', 16);
            // success, failed, logout
            $table->string('result', 16);
            // password, password+2fa, publickey, impersonation, ... (free text from the source)
            $table->string('method', 64)->nullable();
            // Why a failed attempt failed, in words.
            $table->string('reason', 191)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // What was typed (panel email) or the Linux account name.
            $table->string('username', 191)->nullable();
            // The admin behind an impersonated login.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('ip_address', 45);
            $table->string('user_agent', 512)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('country', 96)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('city', 128)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // private, located, unknown, pending
            $table->string('geo_status', 16)->default('pending');
            // SSH rows come from the journal again and again; this keeps one copy.
            $table->char('fingerprint', 40)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['channel', 'occurred_at']);
            $table->index(['result', 'occurred_at']);
            $table->index('ip_address');
            $table->index('user_id');
            $table->index('geo_status');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
