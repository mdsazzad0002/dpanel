<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_queue_workers')) {
            return;
        }

        Schema::create('website_queue_workers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('website_id');
            $table->string('connection', 64)->nullable();
            $table->string('queue', 191)->default('default');
            $table->unsignedSmallInteger('processes')->default(1);
            $table->unsignedSmallInteger('tries')->default(3);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedSmallInteger('sleep')->default(3);
            $table->unsignedInteger('memory')->default(128);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('website_id');
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_queue_workers');
    }
};
