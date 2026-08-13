<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_gateway_chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('provider_id'); // 0 = auto (provider-agnostic) bucket
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('message_count')->default(0);
            $table->string('file_path'); // markdown transcript, appended to on each save
            $table->timestamps();

            $table->index(['provider_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_gateway_chat_sessions');
    }
};
