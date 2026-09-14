<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('self_connection_settings', function (Blueprint $table): void { $table->string('setting_key')->primary(); $table->longText('setting_value'); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('self_connection_settings'); }
};
