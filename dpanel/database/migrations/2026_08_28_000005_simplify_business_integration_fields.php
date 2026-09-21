<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Collapse the separate order/email/SMS URLs and the per-source
        // "business_data_sources" table into one client integration: a
        // single base URL + API key the client implements once, with a
        // toggle per capability (search/order/email/SMS). Every capability
        // is called the same way — POST {base_url} with {"tool": name,
        // ...arguments} — so the client routes internally by the "tool"
        // field instead of us calling a different URL per feature.
        // ------------------------------------------------------------------
        Schema::dropIfExists('business_data_sources');

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'order_api_url', 'order_api_key',
                'email_api_url', 'email_api_key',
                'sms_api_url', 'sms_api_key',
            ]);

            $table->string('integration_base_url')->nullable()->after('description');
            $table->string('integration_api_key')->nullable()->after('integration_base_url');
            $table->boolean('search_enabled')->default(false)->after('integration_api_key');
            $table->boolean('order_enabled')->default(false)->after('search_enabled');
            $table->boolean('email_enabled')->default(false)->after('order_enabled');
            $table->boolean('sms_enabled')->default(false)->after('email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'integration_base_url', 'integration_api_key',
                'search_enabled', 'order_enabled', 'email_enabled', 'sms_enabled',
            ]);

            $table->string('order_api_url')->nullable();
            $table->string('order_api_key')->nullable();
            $table->string('email_api_url')->nullable();
            $table->string('email_api_key')->nullable();
            $table->string('sms_api_url')->nullable();
            $table->string('sms_api_key')->nullable();
        });

        Schema::create('business_data_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type', 40)->default('search');
            $table->string('name');
            $table->string('description');
            $table->string('url');
            $table->string('api_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
        });
    }
};
