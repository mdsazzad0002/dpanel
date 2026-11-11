<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backlog U6: the panel's copy of drust's update runs, for the history
        // and for who started each one. drust's run folders are the truth: a
        // rollback restores this database, and the rows come back from drust.
        Schema::create('panel_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 32)->unique();
            $table->string('action', 16);
            $table->string('status', 16)->index();
            $table->string('step', 32)->nullable();
            $table->string('target_ref', 120)->nullable();
            $table->string('from_version', 120)->nullable();
            $table->string('from_ref', 120)->nullable();
            $table->string('from_commit', 64)->nullable();
            $table->string('to_version', 120)->nullable();
            $table->string('to_ref', 120)->nullable();
            $table->string('to_commit', 64)->nullable();
            $table->string('rollback_of', 32)->nullable();
            $table->boolean('restore_database')->nullable();
            $table->boolean('has_backup')->default(false);
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_updates');
    }
};
