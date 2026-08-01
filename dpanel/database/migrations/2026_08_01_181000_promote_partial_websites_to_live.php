<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('websites')
            ->where('status', 'partial')
            ->update([
                'status' => 'live',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // A live website cannot be identified reliably as formerly partial.
    }
};
