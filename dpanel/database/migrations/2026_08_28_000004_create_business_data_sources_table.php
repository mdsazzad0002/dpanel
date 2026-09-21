<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Named, searchable live-data sources on a business's own site
        // (replaces the old single businesses.api_base_url/api_key pair —
        // a business can now register several, each exposed to the AI as
        // its own "search_<name>" tool it calls on demand, instead of one
        // URL blindly fetched before every reply).
        // ------------------------------------------------------------------
        Schema::create('business_data_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type', 40)->default('search'); // free label for the admin's own organization (e.g. "Products", "Orders", "FAQ")
            $table->string('name');
            $table->string('description');
            $table->string('url');
            $table->string('api_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['api_base_url', 'api_key']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('api_base_url')->nullable();
            $table->string('api_key')->nullable();
        });

        Schema::dropIfExists('business_data_sources');
    }
};
