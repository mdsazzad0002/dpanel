<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog U5: the server's addresses the admin made usable. `address`
        // is on the server's interface (what the gateway sees); behind NAT,
        // `public_address` is what DNS gets.
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('address', 45)->unique();
            $table->string('public_address', 45)->nullable();
            $table->string('interface', 64)->nullable();
            $table->string('label', 100)->nullable();
            // shared or dedicated (to user_id, or to reseller_id who passes it on).
            $table->string('mode', 16)->default('shared');
            $table->unsignedBigInteger('reseller_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->boolean('is_main')->default(false);
            // Shown for a request to the bare IP.
            $table->string('default_website_id', 36)->nullable();
            $table->timestamps();
        });

        // Null: the main IP.
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_address_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['ip_address_id']);
            $table->dropColumn('ip_address_id');
        });
        Schema::dropIfExists('ip_addresses');
    }
};
