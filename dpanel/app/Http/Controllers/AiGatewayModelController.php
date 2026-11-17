<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use App\Services\AiGateway\ModelProber;
use App\Services\AiGateway\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiGatewayModelController extends Controller
{
    public function __construct(
        private readonly AiGatewayService $gateway,
    ) {}

    /** Models live on the Providers & Models page now. */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('ai-gateway.providers.index', $request->only('provider'));
    }

    /** One model row as the Providers & Models page shows it. */
    public static function row(AiGatewayModel $m): array
    {
        return [
            'id' => $m->id,
            'provider_id' => $m->provider_id,
            'name' => $m->name,
            'display_name' => $m->display_name,
            'context_window' => $m->context_window,
            'max_output_tokens' => $m->max_output_tokens,
            'capabilities' => $m->capabilities,
            'input_price' => $m->input_price,
            'output_price' => $m->output_price,
            'is_active' => $m->is_active,
            'priority' => $m->priority,
            'failure_count' => $m->failure_count,
            'cooldown_until' => $m->cooldown_until?->toIso8601String(),
            'auto_disabled' => ! $m->is_active && $m->failure_count >= AiGatewayModel::FAILURE_THRESHOLD,
            'testable' => ! Router::isSpecialOnly($m),
            'last_test' => Cache::get(self::testCacheKey($m)),
        ];
    }

    /**
     * drust's whisper.cpp, the voice-to-text gateway (AiGatewayService::transcribe). It is not a
     * provider model, so the page shows it on its own: on or off, reachable, and when it last worked.
     */
    public static function localTranscription(): array
    {
        $mode = AiGatewayService::localTranscriptionMode();
        $url = (string) config('dpanel.media_transcribe_api_url');
        $reachable = false;

        if ($mode !== 'off' && str_starts_with($url, 'http')) {
            try {
                $health = preg_replace('#/api/v1/media/transcribe$#', '', $url).'/health';
                $reachable = \Illuminate\Support\Facades\Http::timeout(2)->get($health)->json('status') === 'ok';
            } catch (\Throwable) {
                $reachable = false;
            }
        }

        $logs = \App\Models\AiGatewayRequestLog::query()
            ->where('operation', 'transcription')
            ->where('model', AiGatewayService::LOCAL_TRANSCRIPTION_MODEL);

        return [
            'mode' => $mode,
            'model' => AiGatewayService::LOCAL_TRANSCRIPTION_MODEL,
            'reachable' => $reachable,
            'last_used_at' => (clone $logs)->latest('id')->value('created_at')?->toIso8601String(),
            'uses_7d' => (clone $logs)->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Tests the model straight on its own provider (no routing, no
     * failover): a plain reply, then code, tool-calling and image probes.
     * What it passes becomes the model's capabilities, so routing sends
     * tool and image requests only where they work. The result is kept for
     * the page; each call is also in the request log.
     */
    public function test(Request $request, $token, AiGatewayModel $model, ModelProber $prober): JsonResponse
    {
        $model->loadMissing('provider');
        if (! $model->provider || Router::isSpecialOnly($model)) {
            return response()->json(['message' => 'Only chat models can be tested here.'], 422);
        }
        // Four calls; a local model on a CPU can take a while.
        set_time_limit(600);

        $test = $prober->probe($model, $request->user()?->id);
        $test['previous_capabilities'] = $model->capabilities ?? [];
        if ($test['capabilities'] !== null) {
            $model->update(['capabilities' => $test['capabilities']]);
        }
        $test['tested_at'] = now()->toIso8601String();
        Cache::put(self::testCacheKey($model), $test, now()->addDays(30));

        return response()->json($test);
    }

    /** Where the last test result is kept (also read by Local Models' sync). */
    public static function testCacheKey(AiGatewayModel $model): string
    {
        return 'aigateway:model-test:'.$model->id;
    }

    /**
     * Live model list for a provider, called from the "Add/Edit Model"
     * datalist so admins pick from what the account actually has access to
     * instead of a static catalog. Falls back to the local seed list (same
     * one used to pre-populate the datalist on first load) when the live
     * call fails — missing credentials, network error, unsupported driver —
     * so the picker still offers something useful.
     */
    public function remoteModels(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        try {
            $adapter = $this->gateway->adapterFor($provider->driver);
            $models = $adapter->listModels($provider);

            return response()->json(['source' => 'live', 'models' => $models]);
        } catch (AiGatewayException $e) {
            $seed = config('aigateway.driver_default_models.'.$provider->driver, []);

            return response()->json([
                'source' => 'seed',
                'models' => $seed,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:ai_gateway_providers,id'],
            'name' => ['required', 'string', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'context_window' => ['nullable', 'integer', 'min:0'],
            'max_output_tokens' => ['nullable', 'integer', 'min:0'],
            'input_price' => ['nullable', 'numeric', 'min:0'],
            'output_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(AiGatewayModel::CAPABILITIES)],
        ]);

        $provider = AiGatewayProvider::findOrFail($data['provider_id']);
        if ($provider->models()->where('name', $data['name'])->exists()) {
            return $this->toModelsIndex($request)->withErrors(['name' => 'That provider already has a model with this ID.']);
        }

        $provider->models()->create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? null,
            'context_window' => $data['context_window'] ?? 0,
            'max_output_tokens' => $data['max_output_tokens'] ?? 0,
            'input_price' => $data['input_price'] ?? 0,
            'output_price' => $data['output_price'] ?? 0,
            'capabilities' => array_values(array_unique($data['capabilities'] ?? [])) ?: null,
            'is_active' => $data['is_active'] ?? true,
            'priority' => 0,
        ]);
        // A new model goes to the bottom of its provider's drag order.
        AiGatewayProviderController::applyOrder($provider->models()->getQuery(), [], 'priority');

        return $this->toModelsIndex($request)->with('success', 'Model "'.$data['name'].'" added.');
    }

    public function update(Request $request, $token, AiGatewayModel $model): RedirectResponse|JsonResponse
    {
        $targetProviderId = $request->input('provider_id', $model->provider_id);

        $data = $request->validate([
            'provider_id' => ['nullable', 'exists:ai_gateway_providers,id'],
            'name' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('ai_gateway_models', 'name')
                    ->where(fn ($q) => $q->where('provider_id', $targetProviderId))
                    ->ignore($model->id),
            ],
            'display_name' => ['nullable', 'string', 'max:120'],
            'context_window' => ['nullable', 'integer', 'min:0'],
            'max_output_tokens' => ['nullable', 'integer', 'min:0'],
            'input_price' => ['nullable', 'numeric', 'min:0'],
            'output_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(AiGatewayModel::CAPABILITIES)],
        ], [
            'name.unique' => 'That provider already has a model with this ID.',
        ]);

        $reenabling = ! $model->is_active && ($data['is_active'] ?? $model->is_active);
        $movingProvider = isset($data['provider_id']) && (int) $data['provider_id'] !== $model->provider_id;
        $model->fill([
            'provider_id' => $data['provider_id'] ?? $model->provider_id,
            'name' => $data['name'] ?? $model->name,
            'display_name' => $data['display_name'] ?? $model->display_name,
            'context_window' => $data['context_window'] ?? $model->context_window,
            'max_output_tokens' => $data['max_output_tokens'] ?? $model->max_output_tokens,
            'input_price' => $data['input_price'] ?? $model->input_price,
            'output_price' => $data['output_price'] ?? $model->output_price,
            'capabilities' => array_key_exists('capabilities', $data) ? (array_values(array_unique($data['capabilities'] ?? [])) ?: null) : $model->capabilities,
            'is_active' => $data['is_active'] ?? $model->is_active,
        ]);

        if ($reenabling) {
            $model->resetFailover();
        }

        if ($movingProvider) {
            $model->priority = 0;
        }

        $model->save();

        if ($movingProvider) {
            AiGatewayProviderController::applyOrder(AiGatewayModel::query()->where('provider_id', $model->provider_id), [], 'priority');
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'model' => self::row($model->fresh())]);
        }

        return $this->toModelsIndex($request)->with('success', 'Model updated.');
    }

    public function destroy(Request $request, $token, AiGatewayModel $model): RedirectResponse
    {
        $model->delete();

        return $this->toModelsIndex($request)->with('success', 'Model removed.');
    }

    /**
     * Redirect back to the Providers & Models page. Explicit route instead of redirect()->back(), since
     * `back()` follows the Referer header — unreliable in this SPA, where
     * it can resolve to a stale page instead of where the action was
     * actually triggered from.
     */
    private function toModelsIndex(Request $request): RedirectResponse
    {
        return redirect()->route('ai-gateway.providers.index');
    }
}
