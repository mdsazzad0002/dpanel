<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayAgentController extends Controller
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
    }

    public function index(): Response
    {
        $agents = AiGatewayAgent::query()
            ->with(['provider:id,name', 'model:id,name,display_name'])
            ->orderBy('name')
            ->get()
            ->map(fn (AiGatewayAgent $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'description' => $a->description,
                'provider_id' => $a->provider_id,
                'provider_name' => $a->provider?->name,
                'model_id' => $a->model_id,
                'model_name' => $a->model?->display_name ?: $a->model?->name,
                'temperature' => $a->temperature,
                'max_tokens' => $a->max_tokens,
                'is_active' => $a->is_active,
            ]);

        return Inertia::render('AiGateway/Agents/Index', [
            'agents' => $agents,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('AiGateway/Agents/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAgent($request);

        AiGatewayAgent::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'system_prompt' => $data['system_prompt'] ?? null,
            'provider_id' => $data['provider_id'] ?: null,
            'model_id' => $data['model_id'] ?: null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['max_tokens'] ?? null,
            'tools' => $data['tools'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->route('ai-gateway.agents.index')->with('success', 'Agent created.');
    }

    public function edit(AiGatewayAgent $agent): Response
    {
        return Inertia::render('AiGateway/Agents/Edit', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => $agent->description,
                'system_prompt' => $agent->system_prompt,
                'provider_id' => $agent->provider_id,
                'model_id' => $agent->model_id,
                'temperature' => $agent->temperature,
                'max_tokens' => $agent->max_tokens,
                'tools' => $agent->tools,
                'is_active' => $agent->is_active,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, AiGatewayAgent $agent): RedirectResponse
    {
        $data = $this->validateAgent($request);

        $agent->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'system_prompt' => $data['system_prompt'] ?? null,
            'provider_id' => $data['provider_id'] ?: null,
            'model_id' => $data['model_id'] ?: null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['max_tokens'] ?? null,
            'tools' => $data['tools'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->route('ai-gateway.agents.index')->with('success', 'Agent updated.');
    }

    public function destroy(AiGatewayAgent $agent): RedirectResponse
    {
        $name = $agent->name;
        $agent->delete();

        return redirect()->route('ai-gateway.agents.index')->with('success', 'Agent "'.$name.'" deleted.');
    }

    public function test(Request $request, AiGatewayAgent $agent): RedirectResponse
    {
        $data = $request->validate(['prompt' => ['required', 'string', 'max:4000']]);

        try {
            $this->gateway->completeAgent($agent, [
                ['role' => 'user', 'content' => $data['prompt']],
            ]);

            return redirect()->back()->with('success', 'Agent executed successfully.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Agent test failed: '.$e->getMessage());
        }
    }

    private function validateAgent(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'provider_id' => ['nullable', 'exists:ai_gateway_providers,id'],
            'model_id' => ['nullable', 'exists:ai_gateway_models,id'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'tools' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function formOptions(): array
    {
        return [
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
            'models' => AiGatewayModel::query()
                ->with('provider:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'display_name', 'provider_id'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->display_name ?: $m->name, 'provider_id' => $m->provider_id, 'provider_name' => $m->provider?->name]),
        ];
    }
}

