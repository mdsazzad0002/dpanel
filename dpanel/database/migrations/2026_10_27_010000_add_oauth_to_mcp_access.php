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
        // OAuth clients that registered themselves (RFC 7591), e.g. claude.ai.
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('client_secret_hash', 64)->nullable();
            $table->string('client_name');
            $table->json('redirect_uris');
            $table->string('token_endpoint_auth_method', 32)->default('none');
            $table->string('registered_ip', 45)->nullable();
            $table->timestamps();
        });

        // An OAuth grant is stored as an MCP token whose access token
        // rotates on refresh, so it is listed and revoked like any other.
        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            $table->foreignId('oauth_client_id')->nullable()->after('user_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->string('refresh_token_hash', 64)->nullable()->unique()->after('token_hash');
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('oauth_client_id');
            $table->dropUnique(['refresh_token_hash']);
            $table->dropColumn(['refresh_token_hash', 'refresh_expires_at']);
        });

        Schema::dropIfExists('mcp_oauth_clients');
    }
};
