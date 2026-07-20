<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ssh_command_memories');
    }

    public function down(): void
    {
        Schema::create('ssh_command_memories', function ($table): void {
            $table->id();
            $table->string('title');
            $table->longText('command');
            $table->longText('context')->nullable();
            $table->longText('success_output_sample')->nullable();
            $table->string('error_signature', 128)->nullable();
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('error_signature');
            $table->index(['category', 'updated_at']);
        });
    }
};
