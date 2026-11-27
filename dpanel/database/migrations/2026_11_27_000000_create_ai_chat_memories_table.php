<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-term memory of the AI Gateway chat, one row per fact, per admin.
 * Only the one-line index goes into each prompt; bodies are read on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 16);
            $table->string('description', 200);
            $table->text('body');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_memories');
    }
};
