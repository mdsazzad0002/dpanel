<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->longText('summary')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_sessions')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('message');
            $table->string('source', 20)->default('memory');
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        Schema::create('ai_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30);
            $table->longText('input_text');
            $table->longText('normalized_input')->nullable();
            $table->longText('response_text')->nullable();
            $table->longText('command_used')->nullable();
            $table->longText('output_sample')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('priority')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'priority']);
        });

        Schema::create('ai_command_logs', function (Blueprint $table): void {
            $table->id();
            $table->longText('command');
            $table->longText('output')->nullable();
            $table->longText('error_output')->nullable();
            $table->string('source', 20)->default('ssh');
            $table->foreignId('memory_id')->nullable()->constrained('ai_memories')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('ai_sessions')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_command_logs');
        Schema::dropIfExists('ai_memories');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
    }
};

