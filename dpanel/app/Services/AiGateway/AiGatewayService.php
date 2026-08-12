<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
use App\Models\AiGatewayTask;
use App\Models\AiGatewayUsageRecord;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Providers\AnthropicAdapter;
use App\Services\AiGateway\Providers\GeminiAdapter;
use App\Services\AiGateway\Providers\OpenAiAdapter;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Immutable descriptor of a registered provider driver.
 */
class RegisteredAdapter
{
    public function __construct(
        public readonly string $driver,
        public readonly string $label,
    ) {
    }
}

class AiGatewayService
{
    /**
     * @var array<int, ProviderAdapter>
     */
    private array $adapters;

    public function __construct(
        private readonly Router $router,
    ) {
        $this->adapters = [
            new AnthropicAdapter(),
            new OpenAiAdapter(),
            new GeminiAdapter(),
        ];
    }

    /**
     * Registered driver adapters, keyed by driver.
     *
     * @return array<string, RegisteredAdapter>
     */
    public function adapters(): array
    {
        $drivers = [];

        foreach ($this->adapters as $adapter) {
            foreach ($adapter::drivers() as $driver => $label) {
                $drivers[$driver] = new RegisteredAdapter($driver, $label);
            }
        }

        return $drivers;
    }

    public function adapterFor(string $driver): ProviderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supportsDriver($driver)) {
                return $adapter;
            }
        }

        throw Exceptions\AiGatewayException::unsupportedDriver($driver);
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Perform a chat completion through the gateway, applying routing rules
     * and recording usage + audit logs automatically.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{model?: string, agent?: AiGatewayAgent|null, task?: AiGatewayTask|null, temperature?: float, max_tokens?: int, system?: string, channel?: string, operation?: string, created_by?: int|null}  $options
     */
    public function chat(array $messages, array $options = []): array
    {
        $agent = $options['agent'] ?? null;
        $task = $options['task'] ?? null;

        $resolved = $this->router->resolve([
            'model' => $options['model'] ?? null,
            'agent' => $agent,
            'taskType' => $task?->type,
            'taskTitle' => $task?->title,
        ]);

        $provider = $resolved['provider'];
        $modelRecord = $resolved['model'];
        $modelName = $resolved['modelName'];

        $adapter = $this->adapterFor($provider->driver);
        $traceId = (string) Str::uuid();

        $start = microtime(true);

        try {
            $result = $adapter->chat($provider, $modelName, $messages, [
                'system' => $options['system'] ?? null,
                'temperature' => $options['temperature'] ?? ($agent?->temperature)
                    ?? config('aigateway.default_temperature', 0.3),
                'max_tokens' => $options['max_tokens'] ?? $agent?->max_tokens,
            ]);
        } catch (\Throwable $e) {
            $this->recordFailure($provider, $modelRecord, $modelName, $traceId, $options, $task, $e);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $cost = $this->calculateCost(
            $modelRecord,
            $result['input_tokens'],
            $result['output_tokens']
        );

        AiGatewayUsageRecord::record(
            $provider->id,
            $modelRecord?->id,
            $result['input_tokens'],
            $result['output_tokens'],
            $cost
        );

        $log = AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $modelRecord?->id,
            'agent_id' => $agent?->id,
            'task_id' => $task?->id,
            'operation' => $options['operation'] ?? 'chat',
            'model' => $modelName,
            'status' => 'success',
            'request_payload' => config('aigateway.log_payloads', true) ? $this->trimForLog($messages) : null,
            'response_snippet' => config('aigateway.log_payloads', true) ? $this->trimForLog($result['content']) : null,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'latency_ms' => $latencyMs,
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);

        return [
            'content' => $result['content'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'model' => $result['model'],
            'model_id' => $modelRecord?->id,
            'provider' => $provider,
            'latency_ms' => $latencyMs,
            'trace_id' => $traceId,
            'task_id' => $task?->id,
            'raw' => $result['raw'],
        ];
    }

    /**
     * Run a chat through an agent, merging its system prompt / options.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     */
    public function completeAgent(AiGatewayAgent $agent, array $messages, array $options = []): array
    {
        $options['agent'] = $agent;
        $options['channel'] = 'agent';
        $options['operation'] = 'agent';
        $options['created_by'] = $options['created_by'] ?? auth()->id() ?? $agent->created_by;

        return $this->chat($messages, $options);
    }

    /**
     * Run (or re-run) a queued/failed task synchronously.
     */
    public function runTask(AiGatewayTask $task, ?int $userId = null): AiGatewayTask
    {
        $payload = $task->payload;
        $messages = is_array($payload) && isset($payload['messages']) ? $payload['messages'] : [];

        $task->update([
            'status' => AiGatewayTask::STATUS_RUNNING,
            'error' => null,
            'started_at' => Carbon::now(),
            'completed_at' => null,
        ]);

        try {
            $options = [
                'task' => $task,
                'agent' => $task->agent,
                'channel' => 'task',
                'operation' => $task->type,
                'created_by' => $userId ?? $task->created_by,
                'model' => $task->model?->name,
                'temperature' => is_array($payload) ? ($payload['temperature'] ?? null) : null,
                'max_tokens' => is_array($payload) ? ($payload['max_tokens'] ?? null) : null,
                'system' => is_array($payload) ? ($payload['system'] ?? null) : null,
            ];

            $result = $this->chat($messages, $options);

            $task->update([
                'status' => AiGatewayTask::STATUS_SUCCEEDED,
                'response' => $result['content'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'cost' => $result['cost'],
                'latency_ms' => $result['latency_ms'],
                'completed_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            $task->update([
                'status' => AiGatewayTask::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);
        }

        return $task->fresh();
    }

    /**
     * Test connectivity for a provider, persisting the result.
     *
     * @return array{ok:bool, message:string}
     */
    public function testProvider(AiGatewayProvider $provider): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $result = $adapter->ping($provider);

        $provider->update([
            'last_tested_at' => Carbon::now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'fail',
            'last_test_message' => Str::limit($result['message'], 255),
        ]);

        return $result;
    }

    private function calculateCost(?AiGatewayModel $model, int $inputTokens, int $outputTokens): float
    {
        $inputPrice = $model?->input_price ?: 0;
        $outputPrice = $model?->output_price ?: 0;

        return round(
            ($inputTokens / 1_000_000 * $inputPrice) + ($outputTokens / 1_000_000 * $outputPrice),
            6
        );
    }

    private function recordFailure(
        AiGatewayProvider $provider,
        ?AiGatewayModel $model,
        string $modelName,
        string $traceId,
        array $options,
        ?AiGatewayTask $task,
        \Throwable $e
    ): void {
        AiGatewayUsageRecord::record($provider->id, $model?->id, 0, 0, 0, 1);

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $model?->id,
            'agent_id' => isset($options['agent']) && $options['agent'] instanceof AiGatewayAgent ? $options['agent']->id : null,
            'task_id' => $task?->id,
            'operation' => $options['operation'] ?? 'chat',
            'model' => $modelName,
            'status' => 'error',
            'error_message' => Str::limit($e->getMessage(), 2000),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0,
            'latency_ms' => 0,
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);
    }

    private function trimForLog(mixed $value): ?string
    {
        if (is_string($value)) {
            return Str::limit($value, 12000);
        }

        if (is_array($value)) {
            return Str::limit(json_encode($value), 12000);
        }

        return null;
    }
}

