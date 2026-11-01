<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A panel user's own credentials for a website's Git connection.
        // Users other than the one who connected the repository (admins
        // aside) link their own GitHub account or token before they can
        // pull or push; their actions then run with these credentials.
        Schema::create('website_git_user_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('deployment_id');
            $table->foreignId('user_id');
            $table->uuid('github_account_id')->nullable();
            $table->string('auth_username')->nullable();
            $table->text('auth_token')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'user_id']);
            $table->foreign('deployment_id')->references('id')->on('website_git_deployments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('github_account_id')->references('id')->on('github_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_git_user_links');
    }
};
