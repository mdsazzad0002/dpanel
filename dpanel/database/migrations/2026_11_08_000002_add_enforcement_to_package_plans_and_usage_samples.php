<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `report` only tells; `enforce` also makes the OS refuse writes past
        // the limit. Existing plans stay on `report`, so no site stops at upgrade.
        Schema::table('package_plans', function (Blueprint $table): void {
            $table->string('enforcement_mode', 16)->default('report')->after('max_bandwidth_gb');
        });

        Schema::create('account_usage_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at')->index();
            $table->unsignedBigInteger('disk_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_mb')->nullable();
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_mb')->nullable();
            $table->index(['user_id', 'sampled_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // When the disk limit notice was last sent, so it is sent once per crossing.
            $table->timestamp('disk_over_limit_at')->nullable()->after('purge_after');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('disk_over_limit_at'));
        Schema::dropIfExists('account_usage_samples');
        Schema::table('package_plans', fn (Blueprint $table) => $table->dropColumn('enforcement_mode'));
    }
};
