<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_page_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->string('external_id');
            $table->string('parent_post_id')->nullable();
            $table->text('message')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('verb', 32)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->unique(['chat_channel_id', 'activity_type', 'external_id'], 'facebook_activity_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_page_activities');
    }
};
