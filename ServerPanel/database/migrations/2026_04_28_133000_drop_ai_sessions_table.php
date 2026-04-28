<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_sessions')) {
            Schema::drop('ai_sessions');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_sessions')) {
            Schema::create('ai_sessions', function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->longText('summary')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
                $table->index(['status', 'updated_at']);
            });
        }
    }
};

