<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayModelController extends Controller
{
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
                ];
            });

        return Inertia::render('AiGateway/Models/Index', [
            'models' => $models,
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
        ]);
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

        $provider->models()->create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? null,
            'context_window' => $data['context_window'] ?? 0,
            'max_output_tokens' => $data['max_output_tokens'] ?? 0,
            'input_price' => $data['input_price'] ?? 0,
            'output_price' => $data['output_price'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Model "'.$data['name'].'" added.');
    }

    public function update(Request $request, AiGatewayModel $model): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'context_window' => ['nullable', 'integer', 'min:0'],
            'max_output_tokens' => ['nullable', 'integer', 'min:0'],
            'input_price' => ['nullable', 'numeric', 'min:0'],
            'output_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $model->update([
            'display_name' => $data['display_name'] ?? $model->display_name,
            'context_window' => $data['context_window'] ?? $model->context_window,
            'max_output_tokens' => $data['max_output_tokens'] ?? $model->max_output_tokens,
            'input_price' => $data['input_price'] ?? $model->input_price,
            'output_price' => $data['output_price'] ?? $model->output_price,
            'is_active' => $data['is_active'] ?? $model->is_active,
        ]);

        return redirect()->back()->with('success', 'Model updated.');
    }

    public function setDefault(AiGatewayModel $model): RedirectResponse
    {
        $model->provider->models()->update(['is_default' => false]);
        $model->update(['is_default' => true]);
        $model->provider->update(['default_model' => $model->name]);

        return redirect()->back()->with('success', 'Default model set.');
    }

    public function destroy(AiGatewayModel $model): RedirectResponse
    {
        $model->delete();

        return redirect()->back()->with('success', 'Model removed.');
    }
}
