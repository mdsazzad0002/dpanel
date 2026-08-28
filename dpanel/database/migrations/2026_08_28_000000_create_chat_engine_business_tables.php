<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Businesses: the entity a channel's AI replies are trained/scoped
        // to. Owned like chat_channels (created_by), visible via the same
        // admin/reseller/own scoping pattern.
        // ------------------------------------------------------------------
        Schema::create('businesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ------------------------------------------------------------------
        // Products: the things a business sells/offers, grouping Q&A rows.
        // ------------------------------------------------------------------
        Schema::create('business_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
        });

        // ------------------------------------------------------------------
        // Q&A: the trained knowledge injected into the AI system prompt.
        // ------------------------------------------------------------------
        Schema::create('business_qnas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_product_id')->constrained('business_products')->cascadeOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_qnas');
        Schema::dropIfExists('business_products');
        Schema::dropIfExists('businesses');
    }
};
