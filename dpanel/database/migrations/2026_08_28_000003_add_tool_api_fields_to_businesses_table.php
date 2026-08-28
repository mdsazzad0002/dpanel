<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('order_api_url')->nullable()->after('api_key');
            $table->string('order_api_key')->nullable()->after('order_api_url');
            $table->string('email_api_url')->nullable()->after('order_api_key');
            $table->string('email_api_key')->nullable()->after('email_api_url');
            $table->string('sms_api_url')->nullable()->after('email_api_key');
            $table->string('sms_api_key')->nullable()->after('sms_api_url');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['order_api_url', 'order_api_key', 'email_api_url', 'email_api_key', 'sms_api_url', 'sms_api_key']);
        });
    }
};
