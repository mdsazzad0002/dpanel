<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('websites')
            ->whereIn('type', ['alias', 'alis'])
            ->update([
                'hostname' => DB::raw('domain'),
                'status' => 'live',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Existing hostname values cannot be reconstructed safely.
    }
};
