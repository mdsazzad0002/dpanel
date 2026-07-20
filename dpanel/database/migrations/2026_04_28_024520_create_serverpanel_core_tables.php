<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username');
            $table->enum('auth_type', ['password', 'key']);
            $table->text('encrypted_password')->nullable();
            $table->longText('encrypted_private_key')->nullable();
            $table->text('encrypted_private_key_passphrase')->nullable();
            $table->enum('mode', ['setup', 'normal', 'emergency'])->default('setup');
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('kernel')->nullable();
            $table->string('architecture')->nullable();
            $table->unsignedInteger('cpu_cores')->nullable();
            $table->unsignedBigInteger('ram_total_mb')->nullable();
            $table->unsignedBigInteger('disk_total_gb')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_scan_at')->nullable();
            $table->enum('status', ['unknown', 'online', 'offline', 'error'])->default('unknown');
            $table->text('error_message')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'mode']);
            $table->index(['host', 'port']);
        });

        Schema::create('ssh_connection_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['success', 'failed']);
            $table->longText('output')->nullable();
            $table->longText('error_output')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('tested_at');
            $table->timestamps();

            $table->index(['server_id', 'tested_at']);
            $table->index(['status', 'tested_at']);
        });

        Schema::create('server_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('goal');
            $table->enum('status', ['draft', 'running', 'waiting_approval', 'success', 'failed', 'cancelled'])->default('draft');
            $table->enum('priority', ['critical', 'high', 'medium', 'low', 'info'])->default('medium');
            $table->json('ai_plan')->nullable();
            $table->string('final_report_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['priority', 'status']);
        });

        Schema::create('command_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('command_jobs')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('server_tasks')->nullOnDelete();
            $table->longText('command');
            $table->longText('normalized_command')->nullable();
            $table->string('command_hash', 64)->nullable();
            $table->enum('risk_level', ['safe', 'approval_required', 'blocked']);
            $table->text('risk_reason')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'queued', 'running', 'success', 'failed', 'blocked', 'cancelled'])->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->longText('error_output')->nullable();
            $table->longText('ai_summary')->nullable();
            $table->longText('ai_fix_suggestion')->nullable();
            $table->json('ai_fix_commands')->nullable();
            $table->string('report_path')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['risk_level', 'status']);
            $table->index('command_hash');
            $table->index(['created_at', 'status']);
        });

        Schema::create('server_task_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_task_id')->constrained('server_tasks')->cascadeOnDelete();
            $table->foreignId('command_job_id')->nullable()->constrained('command_jobs')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped', 'waiting_approval'])->default('pending');
            $table->unsignedInteger('sort_order');
            $table->longText('result_summary')->nullable();
            $table->timestamps();

            $table->index(['server_task_id', 'sort_order']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('command_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('command_job_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['created', 'classified', 'approved', 'queued', 'started', 'output', 'failed', 'success', 'blocked', 'ai_analyzed', 'fix_suggested', 'retried']);
            $table->longText('message');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['command_job_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('ai_error_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('command_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_signature', 128);
            $table->string('problem_title');
            $table->longText('problem_summary');
            $table->longText('detected_cause')->nullable();
            $table->longText('suggested_fix')->nullable();
            $table->json('fix_commands')->nullable();
            $table->enum('risk_level', ['safe', 'approval_required', 'blocked'])->default('approval_required');
            $table->enum('success_status', ['unknown', 'worked', 'failed', 'partial'])->default('unknown');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('error_signature');
            $table->index(['server_id', 'error_signature']);
        });

        Schema::create('ssh_command_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->longText('command');
            $table->longText('context')->nullable();
            $table->longText('success_output_sample')->nullable();
            $table->string('error_signature', 128)->nullable();
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('error_signature');
            $table->index(['category', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssh_command_memories');
        Schema::dropIfExists('ai_error_resolutions');
        Schema::dropIfExists('command_events');
        Schema::dropIfExists('server_task_steps');
        Schema::dropIfExists('command_jobs');
        Schema::dropIfExists('server_tasks');
        Schema::dropIfExists('ssh_connection_tests');
        Schema::dropIfExists('servers');
    }
};
