<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog M7: nameservers that copy every zone of this server. Each
        // may AXFR the zones and is sent a NOTIFY when one changes.
        Schema::create('dns_secondary_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('address', 45)->unique();
            $table->string('label', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_secondary_servers');
    }
};
