<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_error_resolutions')) {
            Schema::drop('ai_error_resolutions');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_error_resolutions')) {
            Schema::create('ai_error_resolutions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('server_id')->nullable();
                $table->unsignedBigInteger('command_job_id')->nullable();
                $table->string('error_signature', 128);
                $table->string('problem_title');
                $table->longText('problem_summary');
                $table->longText('detected_cause')->nullable();
                $table->longText('suggested_fix')->nullable();
                $table->json('fix_commands')->nullable();
                $table->string('risk_level', 32)->default('approval_required');
                $table->string('success_status', 32)->default('unknown');
                $table->unsignedInteger('usage_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();
                $table->index('error_signature');
                $table->index(['server_id', 'error_signature']);
            });
        }
    }
};

