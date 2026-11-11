<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installed extensions (backlog U7, plans/extension-spec.md). Each one acts
 * through its own API token, owned by its own service account: a `users` row
 * with `is_service_account`, which can never sign in to the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_service_account')->default(false);
        });

        Schema::create('extensions', function (Blueprint $table): void {
            $table->id();
            // The manifest's `id`, such as "acme.uptime".
            $table->string('identifier', 64)->unique();
            $table->string('name', 80);
            $table->string('version', 64);
            $table->string('description', 500)->nullable();
            $table->string('author', 100)->nullable();
            $table->string('homepage')->nullable();
            // The whole manifest as installed.
            $table->json('manifest');
            // The scopes the admin approved; never more than the manifest asks for.
            $table->json('granted_scopes');
            $table->json('events');
            $table->string('ui_title', 40)->nullable();
            $table->string('ui_url')->nullable();
            $table->string('webhook_url')->nullable();
            // HMAC key for webhooks and the page context, encrypted with the app key.
            $table->text('webhook_secret');
            $table->boolean('enabled')->default(true);
            $table->foreignId('service_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedSmallInteger('last_delivery_status')->nullable();
            $table->string('last_delivery_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_service_account');
        });
    }
};
