<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whmcs_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_id', 191)->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('last_request_id', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('whmcs_accounts'); }
};
