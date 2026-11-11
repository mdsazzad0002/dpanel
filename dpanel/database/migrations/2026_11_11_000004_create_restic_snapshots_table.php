<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog W6: each restic snapshot of a website is a restore point.
        // The repository is the truth; this mirrors it so pages need no
        // round trip to remote storage.
        Schema::create('restic_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('website_id')->index();
            $table->string('snapshot_id', 64)->unique();
            $table->timestamp('taken_at')->index();
            $table->unsignedInteger('files_new')->nullable();
            $table->unsignedInteger('files_changed')->nullable();
            $table->unsignedBigInteger('data_added')->nullable();
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->text('warning')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_snapshots');
    }
};
