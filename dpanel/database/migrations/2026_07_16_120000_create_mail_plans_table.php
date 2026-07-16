<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->unsignedInteger('max_storage_mb')->default(1024);
            $table->unsignedInteger('max_mailboxes')->default(5);
            $table->boolean('allow_forwarding')->default(true);
            $table->boolean('allow_aliases')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_plans');
    }
};
