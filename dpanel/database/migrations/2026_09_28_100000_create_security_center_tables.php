<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 32)->unique();
            $table->string('name');
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->text('description')->nullable();
            $table->string('detection_type', 32);
            $table->text('remediation')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->string('server_id')->nullable();
            // Null for a server-wide configuration scan.
            $table->string('website_id')->nullable()->index();
            $table->string('scan_type', 32);
            $table->string('status', 16)->default('queued')->index();
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('threats_found')->default(0);
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('security_scans')->cascadeOnDelete();
            $table->string('website_id')->nullable()->index();
            $table->string('rule_id', 32)->index();
            $table->string('severity', 16)->index();
            $table->string('category', 32)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path', 1024)->nullable();
            $table->unsignedInteger('line_number')->nullable();
            $table->text('evidence')->nullable();
            $table->text('recommendation')->nullable();
            // open | resolved | ignored
            $table->string('status', 16)->default('open')->index();
            $table->boolean('auto_fix_available')->default(false);
            // sha1(website_id|rule_id|file_path): one row per issue across scans.
            $table->string('fingerprint', 40)->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('server_id')->nullable();
            $table->string('website_id')->nullable()->index();
            $table->string('event_type', 48)->index();
            $table->string('severity', 16);
            $table->string('source_ip', 64)->nullable();
            $table->string('request_uri', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('rule_id', 32)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('security_scores', function (Blueprint $table) {
            $table->id();
            $table->string('server_id')->nullable();
            // Null for the whole server.
            $table->string('website_id')->nullable()->index();
            $table->unsignedTinyInteger('overall_score');
            foreach (['firewall', 'waf', 'malware', 'php', 'ssh', 'ssl', 'update', 'backup', 'integrity'] as $category) {
                $table->unsignedTinyInteger($category.'_score')->nullable();
            }
            $table->timestamp('calculated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_scores');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_scans');
        Schema::dropIfExists('security_rules');
    }
};
