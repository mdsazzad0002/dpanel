<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * With explicit_defaults_for_timestamp=OFF (MariaDB before 10.10, older
     * MySQL configs) the first NOT NULL TIMESTAMP column silently becomes
     * "DEFAULT current_timestamp() ON UPDATE current_timestamp()". Every
     * panel request that saves last_seen_at then resets expires_at to now,
     * so the very next request fails the expiry check and is logged out.
     */
    public function up(): void
    {
        if (! Schema::hasTable('panel_sessions') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE panel_sessions MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        // Nothing to restore: the ON UPDATE clause was never intended.
    }
};
