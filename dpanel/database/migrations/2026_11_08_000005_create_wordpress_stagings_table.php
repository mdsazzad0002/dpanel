<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A WordPress site and the site that holds its staging copy (backlog W3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_stagings', function (Blueprint $table) {
            $table->id();
            $table->uuid('production_website_id')->unique();
            $table->uuid('staging_website_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The clone in flight, its direction (`to_staging`, `to_production`) and how it ended.
            $table->uuid('clone_id')->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('stage', 32)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_stagings');
    }
};
