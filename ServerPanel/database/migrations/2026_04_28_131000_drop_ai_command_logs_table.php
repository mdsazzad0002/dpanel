<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_command_logs')) {
            Schema::drop('ai_command_logs');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_command_logs')) {
            Schema::create('ai_command_logs', function (Blueprint $table): void {
                $table->id();
                $table->text('command');
                $table->longText('output')->nullable();
                $table->longText('error_output')->nullable();
                $table->string('source', 20)->default('ssh');
                $table->unsignedBigInteger('memory_id')->nullable();
                $table->unsignedBigInteger('session_id')->nullable();
                $table->unsignedBigInteger('server_id')->nullable();
                $table->timestamps();
                $table->index(['server_id', 'created_at']);
            });
        }
    }
};

