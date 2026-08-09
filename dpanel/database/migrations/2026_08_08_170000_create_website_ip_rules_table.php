<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_ip_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('website_id');
            $table->enum('rule_type', ['ban', 'allow']);
            $table->string('ip_address', 45);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['website_id', 'rule_type', 'ip_address']);
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_ip_rules');
    }
};
