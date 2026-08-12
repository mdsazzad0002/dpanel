<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayProviderController extends Controller
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
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
                    'base_url' => $p->base_url,
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
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('AiGateway/Providers/Create', [
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProvider($request);

        $credentials = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'project' => $validated['project'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $provider = AiGatewayProvider::create([
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(4)),
            'base_url' => $validated['base_url'] ?: null,
            'credentials' => $credentials === [] ? null : $credentials,
            'default_model' => $validated['default_model'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'weight' => (int) ($validated['weight'] ?? 100),
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 0),
            'created_by' => $request->user()?->id,
        ]);

        $this->seedDefaultModels($provider);

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$provider->name.'" created.');
    }



    public function edit(AiGatewayProvider $provider): Response
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
                'last_test_status' => $provider->last_test_status,
                'last_test_message' => $provider->last_test_message,
                'last_tested_at' => $provider->last_tested_at?->toDateTimeString(),
            ],
            'drivers' => array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label], $this->gateway->adapters())),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
    }

    public function update(Request $request, AiGatewayProvider $provider): RedirectResponse
    {
        $validated = $this->validateProvider($request, $provider);

        $data = [
            'name' => $validated['name'],
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
        $existingKey = is_object($existing) ? ($existing['api_key'] ?? null) : null;

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

    public function destroy(AiGatewayProvider $provider): RedirectResponse
    {
        $name = $provider->name;
        $provider->delete();

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$name.'" deleted.');
    }

    public function toggle(Request $request, AiGatewayProvider $provider): RedirectResponse
    {
        $provider->update(['is_active' => $request->boolean('is_active', ! $provider->is_active)]);

        return redirect()->back()->with('success', 'Provider status updated.');
    }

    public function test(AiGatewayProvider $provider): RedirectResponse
    {
        try {
            $result = $this->gateway->testProvider($provider);

            return redirect()->back()->with(
                $result['ok'] ? 'success' : 'error',
                'Provider test: '.$result['message']
            );
        } catch (\Throwable $e) {
            $provider->update(['last_test_status' => 'fail', 'last_test_message' => $e->getMessage(), 'last_tested_at' => now()]);

            return redirect()->back()->with('error', 'Provider test failed: '.$e->getMessage());
        }
    }

    public function syncModels(AiGatewayProvider $provider): RedirectResponse
    {
        $created = 0;
        foreach (config('aigateway.driver_default_models.'.$provider->driver, []) as $definition) {
            $existing = $provider->models()->where('name', $definition['name'])->first();
            if ($existing) {
                $existing->update([
                    'display_name' => $definition['display_name'] ?? $existing->display_name,
                    'context_window' => $definition['context_window'] ?? $existing->context_window,
                    'max_output_tokens' => $definition['max_output_tokens'] ?? $existing->max_output_tokens,
                    'input_price' => $definition['input_price'] ?? $existing->input_price,
                    'output_price' => $definition['output_price'] ?? $existing->output_price,
                ]);
                continue;
            }

            $provider->models()->create([
                'name' => $definition['name'],
                'display_name' => $definition['display_name'] ?? null,
                'context_window' => $definition['context_window'] ?? 0,
                'max_output_tokens' => $definition['max_output_tokens'] ?? 0,
                'capabilities' => $definition['capabilities'] ?? null,
                'input_price' => $definition['input_price'] ?? 0,
                'output_price' => $definition['output_price'] ?? 0,
                'is_active' => true,
            ]);
            $created++;
        }

        $provider->update(['default_model' => $provider->default_model ?: $provider->models()->value('name')]);

        return redirect()->back()->with('success', "Synced provider models ({$created} new).");
    }

    private function seedDefaultModels(AiGatewayProvider $provider): void
    {
        $defaults = config('aigateway.driver_default_models.'.$provider->driver, []);
        $first = true;

        foreach ($defaults as $definition) {
            $provider->models()->create([
                'name' => $definition['name'],
                'display_name' => $definition['display_name'] ?? null,
                'context_window' => $definition['context_window'] ?? 0,
                'max_output_tokens' => $definition['max_output_tokens'] ?? 0,
                'capabilities' => $definition['capabilities'] ?? null,
                'input_price' => $definition['input_price'] ?? 0,
                'output_price' => $definition['output_price'] ?? 0,
                'is_active' => true,
                'is_default' => $first,
            ]);
            $first = false;
        }

        if (! $provider->default_model) {
            $provider->update(['default_model' => $provider->models()->value('name')]);
        }
    }

    private function validateProvider(Request $request, ?AiGatewayProvider $provider = null): array
    {
        $drivers = array_keys($this->gateway->adapters());

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in($drivers)],
            'base_url' => ['nullable', 'url', 'max:255'],
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
}

