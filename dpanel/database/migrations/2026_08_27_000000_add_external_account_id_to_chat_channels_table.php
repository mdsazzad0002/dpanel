<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            // Platform-side account id used to route an incoming webhook
            // payload to the right channel row: Facebook Page id, WhatsApp
            // phone_number_id, etc. Telegram doesn't need this (its webhook
            // URL already embeds the channel id).
            $table->string('external_account_id')->nullable()->after('type');
            $table->index('external_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->dropIndex(['external_account_id']);
            $table->dropColumn('external_account_id');
        });
    }
};
