<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            // What the browser reported (GPS / Wi-Fi), next to the IP-based place.
            $table->decimal('device_latitude', 9, 6)->nullable()->after('longitude');
            $table->decimal('device_longitude', 9, 6)->nullable()->after('device_latitude');
            // Metres, as the browser estimates it.
            $table->unsignedInteger('device_accuracy')->nullable()->after('device_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropColumn(['device_latitude', 'device_longitude', 'device_accuracy']);
        });
    }
};
