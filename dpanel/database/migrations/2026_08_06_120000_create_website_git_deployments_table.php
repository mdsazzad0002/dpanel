<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_git_deployments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('website_id', 64)->unique();
            $table->text('repository_url');
            $table->string('branch', 255)->default('main');
            $table->string('provider', 32)->default('github');
            $table->string('auth_username', 255)->nullable();
            $table->text('auth_token')->nullable();
            $table->enum('auto_action', ['off', 'pull', 'push', 'sync'])->default('off');
            $table->unsignedSmallInteger('interval_minutes')->default(15);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('next_sync_at')->nullable()->index();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });

        Schema::create('website_git_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('deployment_id')->index();
            $table->string('action', 32);
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->foreignId('triggered_by')->nullable();
            $table->timestamps();

            $table->foreign('deployment_id')->references('id')->on('website_git_deployments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_git_logs');
        Schema::dropIfExists('website_git_deployments');
    }
};
