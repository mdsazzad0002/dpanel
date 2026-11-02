<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\LocalLlmClient;
use App\Services\LocalLlmServiceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * AI Gateway → Local Models: manages drust-llm (models on this server) and
 * keeps the matching AI Gateway provider in sync with what is installed.
 */
class AiGatewayLocalLlmController extends Controller
{
    /** The provider row that points at drust-llm. */
    private const PROVIDER_SLUG = 'local-llm';

    /** Service key in config('aigateway.local_llm.services'). */
    private const SERVICE = 'drust-llm';

    public function __construct(
        private readonly LocalLlmClient $llm,
        private readonly LocalLlmServiceManager $service,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('AiGateway/LocalLlm/Index', [
            'configured' => $this->llm->configured(),
            'baseUrl' => $this->llm->baseUrl(),
            'catalog' => config('aigateway.local_llm.catalog', []),
            'initialState' => $this->snapshot(),
        ]);
    }

    /** Polled by the page while a download runs. */
    public function state(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    /**
     * Turns drust-llm on or off (also at boot). The provider follows it, so
     * the gateway stops routing chats to a runner that is off.
     */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'running' => ['required', 'boolean'],
        ]);
        $running = (bool) $data['running'];

        $result = $this->service->setRunning(self::SERVICE, $running);
        if ($result['success']) {
            $this->provider()?->update(['is_active' => $running]);
        }

        return response()->json(array_merge([
            'success' => $result['success'],
            'message' => $result['error'] ?? null,
        ], $this->snapshot()), $result['success'] ? 200 : 422);
    }

    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'starts_with:https://'],
            'id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/'],
        ]);

        return $this->attempt(fn () => $this->llm->pull($data['url'], $data['id'] ?? null));
    }

    public function load($token, string $model): JsonResponse
    {
        return $this->attempt(fn () => $this->llm->load($model));
    }

    public function unload($token, string $model): JsonResponse
    {
        return $this->attempt(fn () => $this->llm->unload($model));
    }

    public function destroy($token, string $model): JsonResponse
    {
        return $this->attempt(function () use ($model) {
            $result = $this->llm->delete($model);
            if ($provider = $this->provider()) {
                $provider->models()->where('name', $model)->delete();
            }

            return $result;
        });
    }

    /**
     * Creates (or refreshes) the AI Gateway provider for drust-llm and adds
     * one model row per installed GGUF file, so chats can route to them.
     */
    public function connect(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $installed = $this->llm->models()['models'];
            $config = $this->llm->status()['config'] ?? [];

            $provider = $this->provider() ?? new AiGatewayProvider([
                'name' => 'Local models (this server)',
                'driver' => 'openai',
                'slug' => self::PROVIDER_SLUG,
                'is_active' => true,
                'weight' => 100,
                'rate_limit_per_minute' => 0,
                'created_by' => $request->user()?->id,
            ]);
            $provider->base_url = $this->llm->baseUrl().'/v1';
            $provider->credentials = ['api_key' => $this->llm->key()];
            $provider->save();

            $names = array_column($installed, 'id');
            $provider->models()->whereNotIn('name', $names)->delete();
            foreach ($names as $name) {
                $provider->models()->updateOrCreate(['name' => $name], [
                    'display_name' => $name.' (local)',
                    'context_window' => (int) ($config['ctx_size'] ?? 4096),
                    'max_output_tokens' => (int) ($config['max_tokens'] ?? 2048),
                    // Text only: drust-llm can't see images or call tools.
                    'capabilities' => ['chat'],
                    'input_price' => 0,
                    'output_price' => 0,
                    'is_active' => true,
                ]);
            }
            if (! $provider->default_model || ! in_array($provider->default_model, $names, true)) {
                $provider->update(['default_model' => $names[0] ?? null]);
            }

            return ['provider' => $this->providerSummary($provider->fresh())];
        });
    }

    private function snapshot(): array
    {
        $status = $this->llm->configured() ? $this->llm->status() : null;
        $models = ['models' => [], 'pulls' => (object) []];
        if ($status !== null) {
            try {
                $models = $this->llm->models();
            } catch (RuntimeException) {
                // Went down between the two calls; the next poll catches up.
            }
        }

        $provider = $this->provider();

        return [
            'online' => $status !== null,
            'service' => $this->service->statuses()[self::SERVICE] ?? null,
            'status' => $status,
            'models' => $models['models'],
            'pulls' => $models['pulls'],
            'provider' => $provider ? $this->providerSummary($provider) : null,
        ];
    }

    private function provider(): ?AiGatewayProvider
    {
        return AiGatewayProvider::query()->where('slug', self::PROVIDER_SLUG)->first();
    }

    private function providerSummary(AiGatewayProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'is_active' => $provider->is_active,
            'models' => $provider->models()->orderBy('name')->pluck('name'),
        ];
    }

    private function attempt(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
