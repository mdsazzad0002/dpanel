<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayModelController extends Controller
{
    public function __construct(
        private readonly AiGatewayService $gateway,
    ) {
    }

    public function index(Request $request): Response
    {
        $providerFilter = $request->integer('provider');

        $models = AiGatewayModel::query()
            ->with('provider:id,name,driver')
            ->when($providerFilter, fn ($q) => $q->where('provider_id', $providerFilter))
            ->orderBy('provider_id')
            ->orderBy('name')
            ->get()
            ->map(function (AiGatewayModel $m): array {
                return [
                    'id' => $m->id,
                    'provider_id' => $m->provider_id,
                    'provider_name' => $m->provider?->name,
                    'provider_driver' => $m->provider?->driver,
                    'name' => $m->name,
                    'display_name' => $m->display_name,
                    'context_window' => $m->context_window,
                    'max_output_tokens' => $m->max_output_tokens,
                    'capabilities' => $m->capabilities,
                    'input_price' => $m->input_price,
                    'output_price' => $m->output_price,
                    'is_active' => $m->is_active,
                    'is_default' => $m->is_default,
                    'failure_count' => $m->failure_count,
                    'cooldown_until' => $m->cooldown_until?->toIso8601String(),
                    'auto_disabled' => ! $m->is_active && $m->failure_count >= AiGatewayModel::FAILURE_THRESHOLD,
                ];
            });

        return Inertia::render('AiGateway/Models/Index', [
            'models' => $models,
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name', 'driver']),
            'providerFilter' => $providerFilter ?: null,
            'defaultModelSeed' => config('aigateway.driver_default_models'),
        ]);
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
        ]);

        $provider = AiGatewayProvider::findOrFail($data['provider_id']);
        $isFirstModel = ! $provider->models()->exists();

        $model = $provider->models()->create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? null,
            'context_window' => $data['context_window'] ?? 0,
            'max_output_tokens' => $data['max_output_tokens'] ?? 0,
            'input_price' => $data['input_price'] ?? 0,
            'output_price' => $data['output_price'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $isFirstModel,
        ]);

        if ($isFirstModel) {
            $provider->update(['default_model' => $model->name]);
        }

        return $this->toModelsIndex($request)->with('success', 'Model "'.$data['name'].'" added.');
    }

    public function update(Request $request, $token, AiGatewayModel $model): RedirectResponse
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
        ], [
            'name.unique' => 'That provider already has a model with this ID.',
        ]);

        $reenabling = ! $model->is_active && ($data['is_active'] ?? $model->is_active);
        $movingProvider = isset($data['provider_id']) && (int) $data['provider_id'] !== $model->provider_id;
        $renaming = isset($data['name']) && $data['name'] !== $model->name;
        $oldProvider = $movingProvider ? $model->provider : null;
        $wasDefault = $model->is_default;

        $model->fill([
            'provider_id' => $data['provider_id'] ?? $model->provider_id,
            'name' => $data['name'] ?? $model->name,
            'display_name' => $data['display_name'] ?? $model->display_name,
            'context_window' => $data['context_window'] ?? $model->context_window,
            'max_output_tokens' => $data['max_output_tokens'] ?? $model->max_output_tokens,
            'input_price' => $data['input_price'] ?? $model->input_price,
            'output_price' => $data['output_price'] ?? $model->output_price,
            'is_active' => $data['is_active'] ?? $model->is_active,
        ]);

        if ($reenabling) {
            $model->resetFailover();
        }

        if ($movingProvider) {
            // Default status is per-provider — moving the model out means
            // it can't stay "the default" for a provider it no longer belongs to.
            $model->is_default = false;

            if ($wasDefault && $oldProvider) {
                $replacement = $oldProvider->models()->where('id', '!=', $model->id)->where('is_active', true)->orderBy('id')->first();
                $oldProvider->update(['default_model' => $replacement?->name]);
                $replacement?->update(['is_default' => true]);
            }
        } elseif ($renaming && $wasDefault) {
            // Still the default for the same provider — keep that provider's
            // default_model pointer in sync with the new name.
            $model->provider->update(['default_model' => $data['name']]);
        }

        $model->save();

        return $this->toModelsIndex($request)->with('success', 'Model updated.');
    }

    public function setDefault(Request $request, $token, AiGatewayModel $model): RedirectResponse
    {
        $model->provider->models()->update(['is_default' => false]);
        $model->update(['is_default' => true]);
        $model->provider->update(['default_model' => $model->name]);

        return $this->toModelsIndex($request)->with('success', 'Default model set.');
    }

    public function destroy(Request $request, $token, AiGatewayModel $model): RedirectResponse
    {
        $model->delete();

        return $this->toModelsIndex($request)->with('success', 'Model removed.');
    }

    /**
     * Redirect back to the models index, preserving the provider filter if
     * one was active. Explicit route instead of redirect()->back(), since
     * `back()` follows the Referer header — unreliable in this SPA, where
     * it can resolve to a stale page instead of where the action was
     * actually triggered from.
     */
    private function toModelsIndex(Request $request): RedirectResponse
    {
        return redirect()->route('ai-gateway.models.index', $request->only('provider'));
    }
}
