<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRoutingRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayRoutingRuleController extends Controller
{
    public function index(): Response
    {
        $rules = AiGatewayRoutingRule::query()
            ->with(['provider:id,name', 'model:id,name,display_name'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (AiGatewayRoutingRule $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'match_type' => $r->match_type,
                'match_value' => $r->match_value,
                'provider_id' => $r->provider_id,
                'provider_name' => $r->provider?->name,
                'model_id' => $r->model_id,
                'model_name' => $r->model?->display_name ?: $r->model?->name,
                'priority' => $r->priority,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('AiGateway/Routing/Index', [
            'rules' => $rules,
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
            'models' => AiGatewayModel::query()
                ->with('provider:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'display_name', 'provider_id'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->display_name ?: $m->name, 'provider_name' => $m->provider?->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request);

        AiGatewayRoutingRule::create([
            'name' => $data['name'],
            'match_type' => $data['match_type'],
            'match_value' => $data['match_type'] === 'always' ? null : ($data['match_value'] ?? null),
            'provider_id' => $data['provider_id'] ?: null,
            'model_id' => $data['model_id'] ?: null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Routing rule added.');
    }

    public function update(Request $request, AiGatewayRoutingRule $rule): RedirectResponse
    {
        $data = $this->validateRule($request);

        $rule->update([
            'name' => $data['name'],
            'match_type' => $data['match_type'],
            'match_value' => $data['match_type'] === 'always' ? null : ($data['match_value'] ?? null),
            'provider_id' => $data['provider_id'] ?: null,
            'model_id' => $data['model_id'] ?: null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Routing rule updated.');
    }

    public function destroy(AiGatewayRoutingRule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect()->back()->with('success', 'Routing rule removed.');
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'match_type' => ['required', Rule::in(['model', 'agent', 'task', 'always'])],
            'match_value' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'exists:ai_gateway_providers,id'],
            'model_id' => ['nullable', 'exists:ai_gateway_models,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
