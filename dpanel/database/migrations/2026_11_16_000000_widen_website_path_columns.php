<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website paths were 120 characters (the panel's default string length),
 * enough for /home/owner/public_html but not for a Windows home such as
 * C:\Users\name\dPanel\sites\a-long-domain-name\public_html. None is
 * indexed, so they can be as long as Windows' long paths need.
 */
return new class extends Migration
{
    private const COLUMNS = ['root_path', 'project_root', 'start_directory'];

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->string($column, 1024)->nullable()->change();
                }
            }
        });
    }

    public function down(): void
    {
        // Narrowing would cut the paths that needed the room.
    }
};
