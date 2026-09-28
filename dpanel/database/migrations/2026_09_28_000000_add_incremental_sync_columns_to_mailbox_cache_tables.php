<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folder signature from a single IMAP STATUS call; when it matches,
        // the message list is served from the cache without fetching anything.
        Schema::table('mailbox_sync_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('uid_next')->nullable()->after('uid_validity');
            $table->unsignedInteger('message_count')->nullable()->after('uid_next');
            $table->unsignedInteger('unseen_count')->nullable()->after('message_count');
            $table->timestamp('messages_synced_at')->nullable()->after('folders_synced_at');
        });

        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            $table->text('recipient')->nullable()->after('sender');
            $table->mediumText('body_text')->nullable()->after('snippet');
            $table->index(['mailbox_id', 'folder', 'seen']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            $table->dropIndex(['mailbox_id', 'folder', 'seen']);
            $table->dropColumn(['recipient', 'body_text']);
        });

        Schema::table('mailbox_sync_states', function (Blueprint $table): void {
            $table->dropColumn(['uid_next', 'message_count', 'unseen_count', 'messages_synced_at']);
        });
    }
};
