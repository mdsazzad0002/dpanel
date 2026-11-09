<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog P6: the highest alert (80 or 100) already sent per account
        // and resource, so each is sent once until use falls back or the
        // period (the month, for bandwidth) starts over.
        Schema::create('usage_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 16);
            $table->string('period', 16)->default('');
            $table->unsignedTinyInteger('level');
            $table->timestamp('notified_at');
            $table->unique(['user_id', 'resource']);
        });

        // These kept the same once-only state for the disk and bandwidth notices.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['disk_over_limit_at', 'bandwidth_over_limit_month']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('disk_over_limit_at')->nullable();
            $table->string('bandwidth_over_limit_month', 7)->nullable();
        });
        Schema::dropIfExists('usage_alerts');
    }
};
