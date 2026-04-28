<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerTaskRequest;
use App\Models\Server;
use App\Models\ServerTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServerTaskController extends Controller
{
    public function index(): Response
    {
        $tasks = ServerTask::query()
            ->with(['server:id,name,host'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ServerPanel/Tasks/Index', [
            'tasks' => $tasks,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServerPanel/Tasks/Index', [
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'createOnly' => true,
        ]);
    }

    public function store(StoreServerTaskRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $task = ServerTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $validated['server_id'],
            'title' => $validated['title'],
            'goal' => $validated['goal'],
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'draft',
            'ai_plan' => [
                ['title' => 'Understand task goal', 'status' => 'pending'],
                ['title' => 'Run diagnostics commands', 'status' => 'pending'],
                ['title' => 'Apply minimal safe fix', 'status' => 'pending'],
                ['title' => 'Verify and report', 'status' => 'pending'],
            ],
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->route('server-tasks.show', $task)->with('success', 'Task created.');
    }

    public function show(ServerTask $task): Response
    {
        $task->load(['server', 'steps.commandJob', 'commandJobs' => fn ($q) => $q->latest()->limit(50)]);

        return Inertia::render('ServerPanel/Tasks/Show', [
            'task' => $task,
        ]);
    }

    public function start(ServerTask $task): RedirectResponse
    {
        $task->forceFill([
            'status' => 'running',
            'started_at' => $task->started_at ?? now(),
        ])->save();

        return back()->with('success', 'Task started.');
    }

    public function cancel(ServerTask $task): RedirectResponse
    {
        $task->forceFill([
            'status' => 'cancelled',
            'finished_at' => now(),
        ])->save();

        return back()->with('success', 'Task cancelled.');
    }
}
