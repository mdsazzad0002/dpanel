<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->nullable()->after('chat_facebook_app_id')
                ->constrained('businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
