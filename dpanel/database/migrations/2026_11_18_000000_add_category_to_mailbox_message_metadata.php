<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            // primary | promotions | updates | social; null until classified.
            $table->string('category', 16)->nullable()->after('snippet');
            $table->index(['mailbox_id', 'folder', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_message_metadata', function (Blueprint $table): void {
            $table->dropIndex(['mailbox_id', 'folder', 'category']);
            $table->dropColumn('category');
        });
    }
};
