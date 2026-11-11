<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog W8: each run of the restore test, kept as a report. One row
        // per run; what each website's restore point showed is in `results`.
        Schema::create('restic_restore_tests', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 16);
            $table->string('status', 16)->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('repository_healthy')->nullable();
            $table->text('repository_message')->nullable();
            $table->unsignedTinyInteger('read_data_percent')->default(0);
            $table->unsignedInteger('websites_tested')->default(0);
            $table->unsignedInteger('websites_failed')->default(0);
            $table->json('results')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_restore_tests');
    }
};
