<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('redis_config_revisions', function (Blueprint $table): void { $table->uuid('id')->primary(); $table->string('website_id', 64)->index(); $table->string('framework', 20); $table->text('config_path'); $table->text('backup_path'); $table->string('status', 20)->default('applied'); $table->foreignId('created_by')->nullable(); $table->timestamp('rolled_back_at')->nullable(); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('redis_config_revisions'); }
};
