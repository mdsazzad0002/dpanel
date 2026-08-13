<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
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
        public readonly string $baseUrl,
        public readonly string $apiKeyUrl,
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
            $meta = $adapter::driverMeta();

            foreach ($adapter::drivers() as $driver => $label) {
                $drivers[$driver] = new RegisteredAdapter(
                    $driver,
                    $label,
                    $meta[$driver]['base_url'] ?? '',
                    $meta[$driver]['api_key_url'] ?? '',
                );
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
     * Perform a chat completion, auto-routing to the highest-weight active
     * provider (or the owner of a specific requested model), recording
     * usage + audit logs automatically.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{model?: string, temperature?: float, max_tokens?: int, system?: string, channel?: string, operation?: string, created_by?: int|null}  $options
     */
    public function chat(array $messages, array $options = []): array
    {
        $resolved = $this->router->resolve(['model' => $options['model'] ?? null]);

        return $this->chatWithProvider($resolved['provider'], $resolved['model'], $resolved['modelName'], $messages, $options);
    }

    /**
     * Perform a chat completion against a specific provider + model,
     * bypassing auto-routing, while still recording usage + audit logs.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{temperature?: float, max_tokens?: int, system?: string, tools?: array, tool_choice?: mixed, channel?: string, operation?: string, created_by?: int|null}  $options
     */
    public function chatWithProvider(AiGatewayProvider $provider, ?AiGatewayModel $modelRecord, string $modelName, array $messages, array $options = []): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $traceId = (string) Str::uuid();

        $start = microtime(true);

        try {
            $result = $adapter->chat($provider, $modelName, $messages, [
                'system' => $options['system'] ?? null,
                'temperature' => $options['temperature'] ?? config('aigateway.default_temperature', 0.3),
                'max_tokens' => $options['max_tokens'] ?? config('aigateway.default_max_tokens', 2048),
                'tools' => $options['tools'] ?? null,
                'tool_choice' => $options['tool_choice'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->recordFailure($provider, $modelRecord, $modelName, $traceId, $options, $e);

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

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $modelRecord?->id,
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
            'raw' => $result['raw'],
            'tool_calls' => $result['tool_calls'] ?? null,
            'finish_reason' => $result['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * Perform a streaming chat completion against a specific provider +
     * model, invoking $onDelta with each text chunk as it arrives. Records
     * usage + audit logs once the stream completes, same as chatWithProvider.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{temperature?: float, max_tokens?: int, system?: string, tools?: array, tool_choice?: mixed, channel?: string, operation?: string, created_by?: int|null}  $options
     * @param  \Closure(string):void  $onDelta
     */
    public function chatStreamWithProvider(AiGatewayProvider $provider, ?AiGatewayModel $modelRecord, string $modelName, array $messages, array $options, \Closure $onDelta): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $traceId = (string) Str::uuid();

        $start = microtime(true);

        try {
            $result = $adapter->stream($provider, $modelName, $messages, [
                'system' => $options['system'] ?? null,
                'temperature' => $options['temperature'] ?? config('aigateway.default_temperature', 0.3),
                'max_tokens' => $options['max_tokens'] ?? config('aigateway.default_max_tokens', 2048),
                'tools' => $options['tools'] ?? null,
                'tool_choice' => $options['tool_choice'] ?? null,
            ], $onDelta);
        } catch (\Throwable $e) {
            $this->recordFailure($provider, $modelRecord, $modelName, $traceId, $options, $e);

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

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $modelRecord?->id,
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
            'raw' => $result['raw'],
            'tool_calls' => $result['tool_calls'] ?? null,
            'finish_reason' => $result['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * Perform a chat completion picking the provider automatically: tries
     * every active, non-cooldown model matching $modelName (any active
     * model if null) in weighted order, failing over to the next candidate
     * whenever the current one returns an upstream error of any kind
     * (rate-limit, auth, bad request, provider-side policy, ...) — the
     * failing model is put in a short cooldown (skipped for a few minutes)
     * so the next request doesn't retry it immediately either. Only a
     * genuinely unexpected (non-gateway) exception aborts immediately.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     */
    public function chatAuto(?string $modelName, array $messages, array $options = [], ?int $providerId = null): array
    {
        $candidates = $this->router->candidates($modelName, $providerId);

        if ($candidates->isEmpty()) {
            throw Exceptions\AiGatewayException::noActiveProvider();
        }

        $lastException = null;

        foreach ($candidates as $modelRecord) {
            try {
                $result = $this->chatWithProvider($modelRecord->provider, $modelRecord, $modelRecord->name, $messages, $options);
                $modelRecord->recordSuccess();

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Streaming counterpart to chatAuto(). $onDelta may be invoked for a
     * candidate that ultimately fails over (partial output before the
     * failure) — callers should treat delta text as provisional until this
     * call returns. Same blanket failover-on-any-upstream-error behavior
     * as chatAuto() — see its docblock.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  \Closure(string):void  $onDelta
     */
    public function chatStreamAuto(?string $modelName, array $messages, array $options, \Closure $onDelta, ?int $providerId = null): array
    {
        $candidates = $this->router->candidates($modelName, $providerId);

        if ($candidates->isEmpty()) {
            throw Exceptions\AiGatewayException::noActiveProvider();
        }

        $lastException = null;

        foreach ($candidates as $modelRecord) {
            try {
                $result = $this->chatStreamWithProvider($modelRecord->provider, $modelRecord, $modelRecord->name, $messages, $options, $onDelta);
                $modelRecord->recordSuccess();

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
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
        \Throwable $e
    ): void {
        AiGatewayUsageRecord::record($provider->id, $model?->id, 0, 0, 0, 1);

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $model?->id,
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

