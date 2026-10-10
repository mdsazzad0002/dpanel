<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read by the edge gateway (drust/src/edge_gateway/source.rs) on every
        // reload; keep column names in step with REDIRECT_RULES_SQL there.
        Schema::create('website_redirect_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('website_id', 64)->index();
            $table->string('name', 120);
            // http_to_https | www_to_root | to_domain; the gateway ignores
            // kinds it does not know.
            $table->string('kind', 32);
            $table->string('target')->nullable();
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('preserve_query')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_redirect_rules');
    }
};
