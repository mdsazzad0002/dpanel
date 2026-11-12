<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dPanel ⇄ dPanel live migration: a saved link to another panel (its URL and
 * a `migration` API token made there), and every transfer run over it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('base_url', 255);
            $table->text('token');
            $table->string('remote_server', 255)->nullable();
            $table->string('remote_version', 64)->nullable();
            $table->json('remote_scopes')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('panel_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('connection_id')->nullable()->constrained('panel_connections')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 8);
            $table->string('kind', 16);
            $table->string('domain', 255)->nullable();
            $table->string('source_ref', 64)->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('percent')->default(0);
            $table->text('message')->nullable();
            $table->json('options')->nullable();
            $table->json('state')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['connection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_transfers');
        Schema::dropIfExists('panel_connections');
    }
};
