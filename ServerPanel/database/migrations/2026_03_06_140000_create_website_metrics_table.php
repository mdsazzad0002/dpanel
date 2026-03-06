<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('website_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('website_id', 64)->index();
            $table->integer('connections')->default(0);
            $table->integer('jobs')->default(0);
            $table->integer('databases')->default(0);
            $table->decimal('disk', 10, 2)->default(0);
            $table->decimal('cpu', 8, 2)->default(0);
            $table->integer('ram')->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_metrics');
    }
};

