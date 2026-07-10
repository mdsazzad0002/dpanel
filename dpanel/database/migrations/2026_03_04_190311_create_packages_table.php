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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('mail_accounts_limit')->nullable();
            $table->unsignedBigInteger('disk_space_mb_limit')->nullable();
            $table->unsignedInteger('databases_limit')->nullable();
            $table->unsignedBigInteger('files_limit')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
