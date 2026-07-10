<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 255);
            $table->string('mailbox', 64);
            $table->string('email', 320)->unique();
            $table->string('password', 255);
            $table->unsignedInteger('quota_mb')->default(1024);
            $table->string('forwarding_to', 255)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index('domain');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
