<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayTask;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayTaskController extends Controller
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
    }

    public function index(Request $request): Response
    {
        $statusFilter = $request->input('status');

        $tasks = AiGatewayTask::query()
            ->with(['agent:id,name', 'provider:id,name', 'model:id,name'])
            ->when($statusFilter && $statusFilter !== 'all', fn ($q) => $q->where('status', $statusFilter))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (AiGatewayTask $t): array {
                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'type' => $t->type,
                    'status' => $t->status,
                    'agent_name' => $t->agent?->name,
                    'provider_name' => $t->provider?->name,
                    'model_name' => $t->model?->name,
                    'input_tokens' => $t->input_tokens,
                    'output_tokens' => $t->output_tokens,
                    'cost' => $t->cost,
                    'latency_ms' => $t->latency_ms,
                    'error' => $t->error,
                    'started_at' => $t->started_at?->toDateTimeString(),
                    'completed_at' => $t->completed_at?->toDateTimeString(),
                    'created_at' => $t->created_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('AiGateway/Tasks/Index', [
            'tasks' => $tasks,
            'agents' => AiGatewayAgent::query()->orderBy('name')->get(['id', 'name']),
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
            'models' => AiGatewayModel::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }


    public function create(): Response
    {
        return Inertia::render('AiGateway/Tasks/Create', [
            'agents' => AiGatewayAgent::query()->orderBy('name')->get(['id', 'name']),
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
            'models' => AiGatewayModel::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }


    public function show(AiGatewayTask $task): Response
    {
        $task->load(['agent', 'provider', 'model', 'createdBy:id,name']);

        return Inertia::render('AiGateway/Tasks/Show', [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'status' => $task->status,
                'payload' => $task->payload,
                'response' => $task->response,
                'error' => $task->error,
                'agent_name' => $task->agent?->name,
                'provider_name' => $task->provider?->name,
                'model_name' => $task->model?->name,
                'input_tokens' => $task->input_tokens,
                'output_tokens' => $task->output_tokens,
                'cost' => $task->cost,
                'latency_ms' => $task->latency_ms,
                'created_by' => $task->createdBy?->name,
                'started_at' => $task->started_at?->toDateTimeString(),
                'completed_at' => $task->completed_at?->toDateTimeString(),
                'created_at' => $task->created_at?->toDateTimeString(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['chat', 'agent', 'embedding'])],
            'agent_id' => ['nullable', 'exists:ai_gateway_agents,id'],
            'provider_id' => ['nullable', 'exists:ai_gateway_providers,id'],
            'model_id' => ['nullable', 'exists:ai_gateway_models,id'],
            'prompt' => ['nullable', 'string', 'max:20000'],
            'system' => ['nullable', 'string', 'max:20000'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'run_now' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'messages' => $data['prompt'] ? [['role' => 'user', 'content' => $data['prompt']]] : [],
            'system' => $data['system'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['max_tokens'] ?? null,
        ];

        $task = AiGatewayTask::create([
            'title' => $data['title'],
            'type' => $data['type'],
            'agent_id' => $data['agent_id'] ?: null,
            'provider_id' => $data['provider_id'] ?: null,
            'model_id' => $data['model_id'] ?: null,
            'payload' => $payload,
            'status' => $data['run_now'] ? AiGatewayTask::STATUS_RUNNING : AiGatewayTask::STATUS_QUEUED,
            'created_by' => $request->user()?->id,
        ]);

        if ($data['run_now']) {
            $task = $this->gateway->runTask($task, $request->user()?->id);
        }

        return redirect()
            ->route('ai-gateway.tasks.show', $task->id)
            ->with('success', 'Task created.');
    }

    public function run(Request $request, AiGatewayTask $task): RedirectResponse
    {
        $this->gateway->runTask($task, $request->user()?->id);

        return redirect()->back()->with('success', 'Task executed.');
    }

    public function destroy(AiGatewayTask $task): RedirectResponse
    {
        $task->delete();

        return redirect()->route('ai-gateway.tasks.index')->with('success', 'Task deleted.');
    }
}
