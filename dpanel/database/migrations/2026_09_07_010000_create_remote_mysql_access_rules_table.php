<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('remote_mysql_access_rules', function (Blueprint $table): void { $table->uuid('id')->primary(); $table->string('ip_address', 64)->unique(); $table->string('note', 255)->nullable(); $table->foreignId('created_by')->nullable(); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('remote_mysql_access_rules'); }
};
