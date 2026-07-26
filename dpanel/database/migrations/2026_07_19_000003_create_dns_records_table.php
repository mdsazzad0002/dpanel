<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may leave the table behind when a CREATE TABLE migration
        // fails while adding a constraint. Rebuild only that empty,
        // untracked partial table; never discard existing DNS records.
        if (Schema::hasTable('dns_records')) {
            if (DB::table('dns_records')->count() > 0) {
                throw new \RuntimeException('dns_records already contains data but its migration is not registered; reconcile it manually before migrating.');
            }
            Schema::drop('dns_records');
        }

        Schema::create('dns_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dns_zone_id');
            $table->string('type', 16);
            $table->string('name');
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedInteger('priority')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('dns_zone_id')->references('id')->on('dns_zones')->cascadeOnDelete();
            $table->index('dns_zone_id');
            $table->index('type');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
