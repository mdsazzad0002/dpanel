<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_ssh_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('host', 253);
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username', 64);
            $table->string('auth_type', 16);
            $table->text('password')->nullable();
            $table->longText('private_key')->nullable();
            $table->text('key_passphrase')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'host', 'port', 'username'], 'migration_ssh_connection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_ssh_connections');
    }
};
