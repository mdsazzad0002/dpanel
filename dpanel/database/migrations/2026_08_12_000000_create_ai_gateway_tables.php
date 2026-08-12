<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Providers: registered upstream AI services (Claude, Codex, OpenAI,
        // Gemini, Local/OpenAI-compatible endpoints).
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('driver', 40); // anthropic | openai | openai_compatible | gemini
            $table->string('slug', 80)->unique();
            $table->string('base_url')->nullable();
            $table->longText('credentials')->nullable(); // encrypted json (api key, org, project)
            $table->string('default_model')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('weight')->default(100); // higher = preferred when routing
            $table->unsignedInteger('rate_limit_per_minute')->default(0); // 0 = unlimited
            $table->json('config')->nullable(); // extra driver options
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable(); // ok | fail
            $table->string('last_test_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'driver']);
        });

        // ------------------------------------------------------------------
        // Models: catalog of models exposed by a provider including pricing.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_gateway_providers')->cascadeOnDelete();
            $table->string('name'); // upstream model id
            $table->string('display_name')->nullable();
            $table->unsignedInteger('context_window')->default(0);
            $table->unsignedInteger('max_output_tokens')->default(0);
            $table->json('capabilities')->nullable(); // ["chat","vision","code"]
            $table->decimal('input_price', 12, 4)->default(0); // per 1M input tokens
            $table->decimal('output_price', 12, 4)->default(0); // per 1M output tokens
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->unique(['provider_id', 'name']);
            $table->index(['provider_id', 'is_active']);
        });

        // ------------------------------------------------------------------
        // Routing rules: match request characteristics to a provider/model.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('match_type', 30)->default('model'); // model | agent | task | always
            $table->string('match_value')->nullable(); // glob / exact value for match_type
            $table->foreignId('provider_id')->nullable()->constrained('ai_gateway_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_gateway_models')->nullOnDelete();
            $table->unsignedInteger('priority')->default(0); // higher evaluated first
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });

        // ------------------------------------------------------------------
        // Agents: reusable, prompt-tuned wrappers around a provider/model.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->longText('system_prompt')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('ai_gateway_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_gateway_models')->nullOnDelete();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->json('tools')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'slug']);
        });

        // ------------------------------------------------------------------
        // Tasks: discrete chat/completion runs performed through the gateway.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('type', 30)->default('chat'); // chat | agent | embedding
            $table->foreignId('agent_id')->nullable()->constrained('ai_gateway_agents')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('ai_gateway_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_gateway_models')->nullOnDelete();
            $table->longText('payload')->nullable(); // request json
            $table->longText('response')->nullable();
            $table->string('status', 20)->default('queued'); // queued | running | succeeded | failed | cancelled
            $table->string('error')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['provider_id', 'created_at']);
        });

        // ------------------------------------------------------------------
        // Usage records: daily aggregated token/cost statistics per model.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('ai_gateway_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_gateway_models')->nullOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->unsignedInteger('failures')->default(0);

            $table->unique(['provider_id', 'model_id', 'usage_date']);
            $table->index(['usage_date']);
        });

        // ------------------------------------------------------------------
        // Request logs: detailed audit trail of every gateway request.
        // ------------------------------------------------------------------
        Schema::create('ai_gateway_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('channel', 40)->default('gateway'); // gateway | agent | task
            $table->foreignId('provider_id')->nullable()->constrained('ai_gateway_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_gateway_models')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('ai_gateway_agents')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('ai_gateway_tasks')->nullOnDelete();
            $table->string('operation', 30)->default('chat'); // chat | completion | embedding
            $table->string('model')->nullable();
            $table->string('status', 20)->default('success'); // success | error | timeout | cancelled
            $table->longText('request_payload')->nullable();
            $table->longText('response_snippet')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['created_at']);
            $table->index(['provider_id', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_gateway_request_logs');
        Schema::dropIfExists('ai_gateway_usage_records');
        Schema::dropIfExists('ai_gateway_tasks');
        Schema::dropIfExists('ai_gateway_agents');
        Schema::dropIfExists('ai_gateway_routing_rules');
        Schema::dropIfExists('ai_gateway_models');
        Schema::dropIfExists('ai_gateway_providers');
    }
};
