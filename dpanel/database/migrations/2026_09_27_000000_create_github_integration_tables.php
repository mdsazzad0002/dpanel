<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-managed GitHub OAuth App, configured from the panel on first
        // use instead of .env. Only one row is active at a time.
        Schema::create('github_oauth_apps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255)->default('dPanel');
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        // Every panel user can link any number of GitHub accounts.
        Schema::create('github_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->index();
            $table->unsignedBigInteger('github_id');
            $table->string('login', 255);
            $table->string('name', 255)->nullable();
            $table->string('avatar_url', 1000)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scopes', 1000)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'github_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('website_git_deployments', function (Blueprint $table): void {
            $table->uuid('github_account_id')->nullable()->after('provider');
            $table->string('repository_full_name', 255)->nullable()->after('repository_url');
            $table->text('webhook_secret')->nullable();
            $table->unsignedBigInteger('github_hook_id')->nullable();
            $table->boolean('deploy_on_push')->default(false);

            $table->foreign('github_account_id')->references('id')->on('github_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_git_deployments', function (Blueprint $table): void {
            $table->dropForeign(['github_account_id']);
            $table->dropColumn(['github_account_id', 'repository_full_name', 'webhook_secret', 'github_hook_id', 'deploy_on_push']);
        });
        Schema::dropIfExists('github_accounts');
        Schema::dropIfExists('github_oauth_apps');
    }
};
