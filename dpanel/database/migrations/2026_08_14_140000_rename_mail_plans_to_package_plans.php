<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('mail_plans', 'package_plans');
    }

    public function down(): void
    {
        Schema::rename('package_plans', 'mail_plans');
    }
};
