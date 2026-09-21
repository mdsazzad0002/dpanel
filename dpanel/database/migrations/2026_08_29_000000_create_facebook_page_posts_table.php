<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_page_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->string('external_post_id')->unique();
            $table->string('external_comment_id')->nullable();
            $table->text('message');
            $table->text('link')->nullable();
            $table->text('first_comment')->nullable();
            $table->string('comment_status', 32)->default('not_requested');
            $table->text('permalink_url')->nullable();
            $table->timestamp('published_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_page_posts');
    }
};
