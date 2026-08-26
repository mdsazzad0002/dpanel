<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Channels: a registered messaging surface (Telegram bot, Facebook
        // page, WhatsApp number, ...). Only "telegram" is implemented today;
        // the type/credentials/settings columns are generic enough for the
        // channels that come later.
        // ------------------------------------------------------------------
        Schema::create('chat_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 40); // telegram | facebook | whatsapp
            $table->string('name');
            $table->longText('credentials')->nullable(); // json: bot_token, etc.
            $table->string('webhook_secret')->nullable();
            $table->json('settings')->nullable(); // system_prompt, ai model override, auto_reply_enabled
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        // ------------------------------------------------------------------
        // Contacts: the person on the other end of a channel (one row per
        // channel + external platform id).
        // ------------------------------------------------------------------
        Schema::create('chat_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->string('external_id'); // platform chat/user id
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_channel_id', 'external_id']);
        });

        // ------------------------------------------------------------------
        // Conversations: one thread per contact per channel.
        // ------------------------------------------------------------------
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->foreignUuid('chat_contact_id')->constrained('chat_contacts')->cascadeOnDelete();
            $table->boolean('is_ai_enabled')->default(true); // false = human has taken over
            $table->string('status', 20)->default('open'); // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_channel_id', 'chat_contact_id']);
            $table->index(['status', 'last_message_at']);
        });

        // ------------------------------------------------------------------
        // Messages: every inbound/outbound message in a conversation.
        // ------------------------------------------------------------------
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('direction', 10); // inbound | outbound
            $table->string('role', 20); // user | assistant | agent
            $table->text('content')->nullable();
            $table->string('type', 20)->default('text'); // text (image|audio|video reserved for later)
            $table->string('external_message_id')->nullable();
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->uuid('ai_trace_id')->nullable(); // links to ai_gateway_request_logs.trace_id
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['chat_conversation_id', 'created_at']);
        });

        // ------------------------------------------------------------------
        // Scheduled / broadcast messages.
        // ------------------------------------------------------------------
        Schema::create('chat_scheduled_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->string('audience_type', 20); // broadcast | contact
            $table->foreignUuid('chat_contact_id')->nullable()->constrained('chat_contacts')->cascadeOnDelete();
            $table->text('content');
            $table->timestamp('run_at');
            $table->string('status', 20)->default('pending'); // pending | dispatched | failed | cancelled
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'run_at']);
        });

        // ------------------------------------------------------------------
        // Per-recipient delivery tracking for scheduled/broadcast messages.
        // ------------------------------------------------------------------
        Schema::create('chat_broadcast_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_scheduled_message_id')->constrained('chat_scheduled_messages')->cascadeOnDelete();
            $table->foreignUuid('chat_contact_id')->constrained('chat_contacts')->cascadeOnDelete();
            $table->foreignUuid('chat_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_scheduled_message_id', 'chat_contact_id'], 'chat_broadcast_deliveries_msg_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_broadcast_deliveries');
        Schema::dropIfExists('chat_scheduled_messages');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('chat_contacts');
        Schema::dropIfExists('chat_channels');
    }
};
