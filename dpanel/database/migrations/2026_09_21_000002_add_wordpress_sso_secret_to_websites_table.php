<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'wordpress_sso_secret')) {
                $table->string('wordpress_sso_secret')->nullable()->after('ssl_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'wordpress_sso_secret')) {
                $table->dropColumn('wordpress_sso_secret');
            }
        });
    }
};
