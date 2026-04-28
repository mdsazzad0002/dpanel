<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommandJobRequest;
use App\Models\CommandJob;
use App\Models\Server;
use App\Services\ServerPanel\CommandRunnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CommandJobController extends Controller
{
    public function index(?Server $server = null): Response
    {
        $jobs = CommandJob::query()
            ->with(['server:id,name,host', 'requestedBy:id,name,email', 'approvedBy:id,name,email'])
            ->when($server, fn ($query) => $query->where('server_id', $server->id))
            ->when(request('status'), fn ($query, $value) => $query->where('status', $value))
            ->when(request('risk'), fn ($query, $value) => $query->where('risk_level', $value))
            ->when(request('server_id'), fn ($query, $value) => $query->where('server_id', $value))
            ->when(request('q'), fn ($query, $value) => $query->where('command', 'like', '%'.$value.'%'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ServerPanel/Commands/Index', [
            'jobs' => $jobs,
            'filters' => array_merge(request()->only(['status', 'risk', 'server_id', 'q']), [
                'server_id' => request('server_id') ?: $server?->id,
            ]),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCommandJobRequest $request, CommandRunnerService $commandRunner): RedirectResponse
    {
        $validated = $request->validated();
        $server = Server::query()->findOrFail($validated['server_id']);

        $job = $commandRunner->createAndDispatch(
            $server,
            $validated['command'],
            $request->user(),
            [
                'task_id' => $validated['task_id'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'tags' => $validated['tags'] ?? [],
            ],
        );

        return redirect()->route('commands.show', $job)->with('success', 'Command submitted with status: '.$job->status);
    }

    public function show(CommandJob $commandJob): Response
    {
        $commandJob->load([
            'server',
            'events',
            'children' => fn ($query) => $query->latest(),
            'requestedBy:id,name,email',
            'approvedBy:id,name,email',
        ]);

        return Inertia::render('ServerPanel/Commands/Show', [
            'job' => $commandJob,
            'canApprove' => $this->canApprove(),
        ]);
    }

    public function approve(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        abort_unless($this->canApprove(), 403, 'Admin approval required.');
        if ($commandJob->status !== 'pending_approval') {
            return back()->with('error', 'Only pending commands can be approved.');
        }

        $commandRunner->approve($commandJob, request()->user());

        return back()->with('success', 'Command approved and queued.');
    }

    public function cancel(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        if (! in_array($commandJob->status, ['draft', 'pending_approval', 'queued'], true)) {
            return back()->with('error', 'This command can no longer be cancelled.');
        }

        $commandRunner->cancel($commandJob);

        return back()->with('success', 'Command cancelled.');
    }

    public function retry(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        $newJob = $commandRunner->createAndDispatch(
            $commandJob->server,
            $commandJob->command,
            request()->user(),
            [
                'parent_id' => $commandJob->id,
                'task_id' => $commandJob->task_id,
                'tags' => array_merge((array) $commandJob->tags, ['retry']),
            ],
        );
        $newJob->increment('retry_count');

        $newJob->events()->create([
            'type' => 'retried',
            'message' => 'Retry created from command #'.$commandJob->id,
            'meta' => ['source_command_job_id' => $commandJob->id],
        ]);

        return redirect()->route('commands.show', $newJob)->with('success', 'Retry queued with status: '.$newJob->status);
    }

    public function runSuggestedFix(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        if ($commandJob->risk_level === 'blocked') {
            return back()->with('error', 'Blocked fix command cannot run.');
        }

        if ($commandJob->risk_level === 'approval_required' && ! $this->canApprove()) {
            return back()->with('error', 'Admin approval required for this fix.');
        }

        if ($commandJob->risk_level === 'approval_required') {
            $commandRunner->approve($commandJob, request()->user());
        } else {
            $commandRunner->dispatchExecution($commandJob);
        }

        return back()->with('success', 'Suggested fix queued.');
    }

    public function downloadReport(CommandJob $commandJob)
    {
        abort_if(! $commandJob->report_path, 404, 'Report not generated yet.');
        abort_if(! Storage::disk('local')->exists($commandJob->report_path), 404, 'Report not found.');

        return Storage::disk('local')->download($commandJob->report_path, 'command-'.$commandJob->uuid.'.txt');
    }

    private function canApprove(): bool
    {
        return (bool) request()->user()?->hasRole('admin');
    }
}
