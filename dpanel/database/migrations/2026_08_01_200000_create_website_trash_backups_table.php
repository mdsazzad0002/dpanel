<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_trash_backups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('website_id', 64)->index();
            $table->string('domain')->index();
            $table->string('file_name');
            $table->text('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->index();
            $table->foreignId('assigned_reseller_id')->nullable()->index();
            $table->foreignId('deleted_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_trash_backups');
    }
};
