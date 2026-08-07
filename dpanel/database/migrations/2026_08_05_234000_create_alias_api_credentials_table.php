<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('alias_api_credentials', function (Blueprint $table): void { $table->uuid('id')->primary(); $table->string('website_id',64)->unique(); $table->string('token_hash',64)->unique(); $table->string('token_hint',12); $table->string('challenge_token',64); $table->boolean('enabled')->default(false); $table->foreignId('created_by')->nullable(); $table->timestamp('last_used_at')->nullable(); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('alias_api_credentials'); }
};
