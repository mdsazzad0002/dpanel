<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named allow-list of MCP tools. A token tied to a group only sees
        // and calls those tools; a token without a group gets every tool.
        Schema::create('mcp_access_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->json('tools');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            // Restrict: deleting a group must never silently widen a token to every tool.
            $table->foreignId('access_group_id')->nullable()->after('oauth_client_id')->constrained('mcp_access_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_group_id');
        });

        Schema::dropIfExists('mcp_access_groups');
    }
};
