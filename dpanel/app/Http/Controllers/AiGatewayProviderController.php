<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\ChatHistoryStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiGatewayProviderController extends Controller
{
    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly ChatHistoryStore $chatHistory,
    ) {
    }

    public function index(): Response
    {
        $providers = AiGatewayProvider::query()
            ->withCount('models')
            ->orderByDesc('is_active')
            ->orderByDesc('weight')
            ->orderBy('name')
            ->get()
            ->map(function (AiGatewayProvider $p): array {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'driver' => $p->driver,
                    'driver_label' => $p->getDriverLabel(),
                    'default_model' => $p->default_model,
                    'is_active' => $p->is_active,
                    'weight' => $p->weight,
                    'rate_limit_per_minute' => $p->rate_limit_per_minute,
                    'models_count' => $p->models_count,
                    'has_credentials' => $this->hasCredentials($p),
                    'last_tested_at' => $p->last_tested_at?->toDateTimeString(),
                    'last_test_status' => $p->last_test_status,
                    'last_test_message' => $p->last_test_message,
                ];
            });

        return Inertia::render('AiGateway/Providers/Index', [
            'providers' => $providers,
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label, 'base_url' => $d->baseUrl, 'api_key_url' => $d->apiKeyUrl], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('AiGateway/Providers/Create', [
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label, 'base_url' => $d->baseUrl, 'api_key_url' => $d->apiKeyUrl], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProvider($request);
        $name = $validated['name'] ?: $this->defaultProviderNameForDriver($validated['driver']);

        $credentials = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'project' => $validated['project'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $provider = AiGatewayProvider::create([
            'name' => $name,
            'driver' => $validated['driver'],
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'base_url' => $validated['base_url'] ?: null,
            'credentials' => $credentials === [] ? null : $credentials,
            'default_model' => $validated['default_model'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'weight' => (int) ($validated['weight'] ?? 100),
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 0),
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$provider->name.'" created. Add models for it on the Models page, or use "Sync Default Models" on the provider\'s edit page.');
    }



    public function edit($token , AiGatewayProvider $provider): Response
    {
        return Inertia::render('AiGateway/Providers/Edit', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'slug' => $provider->slug,
                'driver' => $provider->driver,
                'driver_label' => $provider->getDriverLabel(),
                'base_url' => $provider->base_url,
                'default_model' => $provider->default_model,
                'is_active' => $provider->is_active,
                'weight' => $provider->weight,
                'rate_limit_per_minute' => $provider->rate_limit_per_minute,
                'has_credentials' => $this->hasCredentials($provider),
                'api_key' => $provider->getApiKey(),
                'last_test_status' => $provider->last_test_status,
                'last_test_message' => $provider->last_test_message,
                'last_tested_at' => $provider->last_tested_at?->toDateTimeString(),
                'models' => $provider->models()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get(['name', 'display_name']),
            ],
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label, 'base_url' => $d->baseUrl, 'api_key_url' => $d->apiKeyUrl], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function update(Request $request, $token, AiGatewayProvider $provider): RedirectResponse
    {
        $validated = $this->validateProvider($request, $provider);
        $name = $validated['name'] ?: $this->defaultProviderNameForDriver($validated['driver']);

        $data = [
            'name' => $name,
            'driver' => $validated['driver'],
            'base_url' => $validated['base_url'] ?: null,
            'default_model' => $validated['default_model'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'weight' => (int) ($validated['weight'] ?? 100),
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 0),
        ];

        $credentials = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'project' => $validated['project'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Keep the existing key when the field is left blank (masked in UI).
        $existing = $provider->credentials;
        $existingKey = is_array($existing) ? ($existing['api_key'] ?? null) : null;

        if (($validated['api_key'] ?? null) === null && $credentials === []) {
            $provider->credentials = $existingKey ? ['api_key' => $existingKey] : null;
        } else {
            $provider->credentials = $credentials === [] ? null : $credentials;
        }

        $provider->fill($data);
        $provider->save();

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$provider->name.'" updated.');
    }

    public function destroy($token, AiGatewayProvider $provider): RedirectResponse
    {
        $name = $provider->name;
        $provider->delete();

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$name.'" deleted.');
    }

    public function toggle(Request $request, $token, AiGatewayProvider $provider): RedirectResponse
    {
        $provider->update(['is_active' => $request->boolean('is_active', ! $provider->is_active)]);

        return redirect()->back()->with('success', 'Provider status updated.');
    }

    public function test(Request $request, $token, AiGatewayProvider $provider): RedirectResponse|JsonResponse
    {
        try {
            $result = $this->gateway->testProvider($provider);
            $message = 'Provider test: '.$result['message'];
        } catch (\Throwable $e) {
            $provider->update(['last_test_status' => 'fail', 'last_test_message' => $e->getMessage(), 'last_tested_at' => now()]);
            $result = ['ok' => false, 'message' => $e->getMessage()];
            $message = 'Provider test failed: '.$e->getMessage();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'last_tested_at' => $provider->fresh()->last_tested_at?->toDateTimeString(),
            ]);
        }

        return redirect()->back()->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function chat(Request $request, $token): Response
    {
        $providers = AiGatewayProvider::query()
            ->where('is_active', true)
            ->with(['models' => fn ($q) => $q->where('is_active', true)->orderByDesc('is_default')->orderBy('name')])
            ->orderByDesc('weight')
            ->orderBy('name')
            ->get();

        // No explicit ?provider= means "let the gateway pick" (Auto mode);
        // an explicit request pins the chat to that one provider.
        $requestedId = $request->query('provider');
        $selected = $requestedId ? $providers->firstWhere('id', (int) $requestedId) : null;

        return Inertia::render('AiGateway/Chat', [
            'providers' => $providers->map(fn (AiGatewayProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'driver' => $p->driver,
                'driver_label' => $p->getDriverLabel(),
                'default_model' => $p->default_model,
                'models' => $p->models->map(fn ($m) => ['name' => $m->name, 'display_name' => $m->display_name])->values(),
            ])->values(),
            'initialProviderId' => $selected?->id ?? ($providers->count() > 1 ? '__auto__' : $providers->first()?->id),
        ]);
    }

    public function chatSend(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant,system'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $options = [
            'channel' => 'playground',
            'operation' => 'chat',
            'created_by' => $request->user()?->id,
        ];

        try {
            if ($data['model']) {
                // A specific model was picked — pin to exactly that, no failover.
                $modelRecord = $provider->models()->where('name', $data['model'])->first();
                $result = $this->gateway->chatWithProvider($provider, $modelRecord, $data['model'], $data['messages'], $options);
            } else {
                // No model picked — auto-select among this provider's own models,
                // with failover between them on rate-limit/quota errors.
                $result = $this->gateway->chatAuto(null, $data['messages'], $options, $provider->id);
            }

            return response()->json([
                'ok' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Stream a chat completion as Server-Sent Events. Emits `delta` events
     * as text arrives and a final `done` (or `error`) event.
     */
    public function chatStream(Request $request, $token, AiGatewayProvider $provider): StreamedResponse
    {
        try {
            $data = $request->validate([
                'model' => ['nullable', 'string', 'max:255'],
                'messages' => ['required', 'array', 'min:1'],
                'messages.*.role' => ['required', 'in:user,assistant,system'],
                'messages.*.content' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sseValidationError($e);
        }

        $userId = $request->user()?->id;

        $response = new StreamedResponse(function () use ($data, $provider, $userId): void {
            $send = function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $options = ['channel' => 'playground', 'operation' => 'chat', 'created_by' => $userId];
            $onDelta = function (string $delta) use ($send): void {
                $send('delta', ['text' => $delta]);
            };

            try {
                if ($data['model']) {
                    // A specific model was picked — pin to exactly that, no failover.
                    $modelRecord = $provider->models()->where('name', $data['model'])->first();
                    $result = $this->gateway->chatStreamWithProvider($provider, $modelRecord, $data['model'], $data['messages'], $options, $onDelta);
                } else {
                    // No model picked — auto-select among this provider's own models,
                    // with failover between them on rate-limit/quota errors.
                    $result = $this->gateway->chatStreamAuto(null, $data['messages'], $options, $onDelta, $provider->id);
                }

                $send('done', [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ]);
            } catch (\Throwable $e) {
                $send('error', ['message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Streaming endpoints can't rely on Laravel's default validation-failure
     * handling: our `Accept: text/event-stream` header isn't recognised as
     * "expects JSON", so a failed $request->validate() would otherwise
     * silently redirect back to whatever page the session last loaded.
     * Emit a proper SSE `error` event instead.
     */
    private function sseValidationError(\Illuminate\Validation\ValidationException $e): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($e): void {
            echo 'event: error'."\n";
            echo 'data: '.json_encode(['message' => $e->validator->errors()->first() ?: 'Invalid request.'])."\n\n";
            @flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Synthetic "provider id" bucket used to store auto-mode chat history,
     * since a single auto conversation may be answered by a different
     * provider on each turn (failover). Real provider ids start at 1.
     */
    private const AUTO_BUCKET = 0;

    /**
     * Non-streaming auto-routed chat completion: picks a provider
     * automatically for $model (or any active model if null), with
     * rate-limit failover across providers/models. See AiGatewayService::chatAuto().
     */
    public function chatAutoSend(Request $request, $token): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant,system'],
            'messages.*.content' => ['required', 'string'],
        ]);

        try {
            $result = $this->gateway->chatAuto($data['model'] ?? null, $data['messages'], [
                'channel' => 'playground',
                'operation' => 'chat',
                'created_by' => $request->user()?->id,
            ]);

            return response()->json([
                'ok' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'provider_id' => $result['provider']->id,
                'provider_name' => $result['provider']->name,
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Streaming counterpart to chatAutoSend().
     */
    public function chatAutoStream(Request $request, $token): StreamedResponse
    {
        try {
            $data = $request->validate([
                'model' => ['nullable', 'string', 'max:255'],
                'messages' => ['required', 'array', 'min:1'],
                'messages.*.role' => ['required', 'in:user,assistant,system'],
                'messages.*.content' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sseValidationError($e);
        }

        $userId = $request->user()?->id;

        $response = new StreamedResponse(function () use ($data, $userId): void {
            $send = function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            try {
                $result = $this->gateway->chatStreamAuto(
                    $data['model'] ?? null,
                    $data['messages'],
                    ['channel' => 'playground', 'operation' => 'chat', 'created_by' => $userId],
                    function (string $delta) use ($send): void {
                        $send('delta', ['text' => $delta]);
                    }
                );

                $send('done', [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'provider_id' => $result['provider']->id,
                    'provider_name' => $result['provider']->name,
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ]);
            } catch (\Throwable $e) {
                $send('error', ['message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function chatHistoryIndexAuto(Request $request, $token): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->chatHistory->list(self::AUTO_BUCKET, $user->id, $user->hasRole('admin')),
        ]);
    }

    public function chatHistoryShowAuto(Request $request, $token, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $data = $this->chatHistory->load(self::AUTO_BUCKET, $ownerId, $session);

        if (! $data) {
            return response()->json(['message' => 'Chat not found.'], 404);
        }

        return response()->json($data);
    }

    public function chatHistorySaveAuto(Request $request, $token): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $user = $request->user();
        $result = $this->chatHistory->save(self::AUTO_BUCKET, $user->id, $data['session_id'] ?? null, $data['messages'], $user->name);

        return response()->json($result);
    }

    public function chatHistoryDestroyAuto(Request $request, $token, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $this->chatHistory->delete(self::AUTO_BUCKET, $ownerId, $session);

        return response()->json(['ok' => true]);
    }

    public function chatHistoryIndex(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->chatHistory->list($provider->id, $user->id, $user->hasRole('admin')),
        ]);
    }

    public function chatHistoryShow(Request $request, $token, AiGatewayProvider $provider, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $data = $this->chatHistory->load($provider->id, $ownerId, $session);

        if (! $data) {
            return response()->json(['message' => 'Chat not found.'], 404);
        }

        return response()->json($data);
    }

    public function chatHistorySave(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $user = $request->user();
        $result = $this->chatHistory->save($provider->id, $user->id, $data['session_id'] ?? null, $data['messages'], $user->name);

        return response()->json($result);
    }

    public function chatHistoryDestroy(Request $request, $token, AiGatewayProvider $provider, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $this->chatHistory->delete($provider->id, $ownerId, $session);

        return response()->json(['ok' => true]);
    }


    private function validateProvider(Request $request, ?AiGatewayProvider $provider = null): array
    {
        $drivers = array_keys($this->gateway->adapters());

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in($drivers)],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'organization' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],
            'default_model' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function hasCredentials(AiGatewayProvider $provider): bool
    {
        return $provider->getApiKey() !== null;
    }

    private function defaultProviderNameForDriver(string $driver): string
    {
        return match ($driver) {
            'anthropic' => 'Anthropic Provider',
            'openai' => 'OpenAI Provider',
            'openrouter' => 'OpenRouter Provider',
            'groq' => 'Groq Provider',
            'deepseek' => 'DeepSeek Provider',
            'mistral' => 'Mistral Provider',
            'cerebras' => 'Cerebras Provider',
            'gemini' => 'Gemini Provider',
            default => Str::headline(str_replace('_', ' ', $driver)).' Provider',
        };
    }
}
