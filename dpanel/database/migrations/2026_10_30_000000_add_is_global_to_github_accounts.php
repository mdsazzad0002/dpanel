<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin-chosen account that Git deployments fall back to when
        // they have neither a linked account nor a manual token.
        Schema::table('github_accounts', function (Blueprint $table): void {
            $table->boolean('is_global')->default(false)->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('github_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_global');
        });
    }
};
