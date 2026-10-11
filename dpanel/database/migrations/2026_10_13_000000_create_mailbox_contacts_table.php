<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webmail address book: one list per mailbox.
        Schema::create('mailbox_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            // Saved by the user, not just remembered from sent mail.
            $table->boolean('starred')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_contacts');
    }
};
