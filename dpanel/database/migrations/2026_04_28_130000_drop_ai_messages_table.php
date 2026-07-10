<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_messages')) {
            Schema::drop('ai_messages');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->string('role', 20);
                $table->longText('message');
                $table->string('source', 50)->nullable();
                $table->timestamps();
                $table->index(['session_id', 'created_at']);
            });
        }
    }
};

