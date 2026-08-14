<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('mailbox_id');
            $table->string('folder', 255);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->json('folders')->nullable();
            $table->timestamp('folders_synced_at')->nullable();
            $table->timestamps();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->unique(['mailbox_id', 'folder']);
        });

        Schema::create('mailbox_message_metadata', function (Blueprint $table): void {
            $table->id();
            $table->uuid('mailbox_id');
            $table->string('folder', 255);
            $table->unsignedBigInteger('uid');
            $table->text('subject')->nullable();
            $table->text('sender')->nullable();
            $table->string('message_date')->nullable();
            $table->boolean('seen')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->text('snippet')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->unique(['mailbox_id', 'folder', 'uid']);
            $table->index(['mailbox_id', 'folder', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_message_metadata');
        Schema::dropIfExists('mailbox_sync_states');
    }
};
