<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->default('cpanel');
            $table->string('original_name');
            $table->text('archive_path');
            $table->unsignedBigInteger('archive_size')->default(0);
            $table->string('status', 32)->default('uploaded');
            $table->json('inventory')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('assigned_reseller_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_imports');
    }
};
