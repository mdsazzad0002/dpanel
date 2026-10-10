<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\AppProject;
use App\Models\PortShare;
use App\Models\Website;
use App\Services\EdgeGatewayReloader;
use App\Services\Projects\AppProjectProcessService;
use App\Services\Projects\LocalPortScanner;
use App\Services\Projects\ProjectPortAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Node.js / Python projects: apps run from any folder of a site owner's home. */
class AppProjectController extends Controller
{
    public function __construct(protected AppProjectProcessService $process) {}

    public function nodeIndex(Request $request): Response
    {
        return $this->index($request, 'node', 'NodeApps/Index', ['18', '20', '22']);
    }

    public function pythonIndex(Request $request): Response
    {
        return $this->index($request, 'python', 'PythonApps/Index', ['3.8', '3.10', '3.12']);
    }

    /** @param array<int, string> $versions */
    private function index(Request $request, string $runtime, string $page, array $versions): Response
    {
        $projects = AppProject::query()->visibleTo($request->user())->where('runtime', $runtime)->orderBy('name')->get();
        $shares = PortShare::query()
            ->whereIn('app_project_id', $projects->pluck('id'))
            ->with('website:id,domain,enable_ssl')
            ->get()
            ->groupBy('app_project_id');

        return Inertia::render($page, [
            'projects' => $projects->map(fn (AppProject $project): array => $project->toRow() + [
                // Read-only here: sharing is done from the website's Manage page.
                'shares' => ($shares->get($project->id) ?? collect())->map(fn (PortShare $share): array => [
                    'id' => $share->id,
                    'website_id' => (string) $share->website_id,
                    'url' => ($share->website?->enable_ssl ? 'https://' : 'http://').$share->website?->domain.($share->path_prefix === '/' ? '' : $share->path_prefix),
                    'enabled' => $share->enabled,
                ])->values(),
            ])->values(),
            'owners' => AppProject::ownersVisibleTo($request->user()),
            'versions' => $versions,
        ]);
    }

    public function store(Request $request, string $token, ProjectPortAllocator $ports, LocalPortScanner $scanner): RedirectResponse
    {
        $validated = $request->validate([
            'runtime' => ['required', Rule::in(AppProject::RUNTIMES)],
            'site_owner' => ['required', 'string', Rule::in(AppProject::ownersVisibleTo($request->user()))],
            'port' => ['nullable', 'integer', 'between:1024,65535'],
            'start_now' => ['boolean'],
            ...$this->settingsRules(),
        ]);

        $port = isset($validated['port']) ? (int) $validated['port'] : $ports->allocate($validated['runtime']);
        $this->assertPortFree($port, null, $scanner);

        $project = DB::transaction(function () use ($request, $validated, $port): AppProject {
            $project = new AppProject([
                'runtime' => $validated['runtime'],
                'site_owner' => $validated['site_owner'],
                'port' => $port,
                'status' => 'stopped',
                'unit_key' => 'pending-'.bin2hex(random_bytes(8)),
                'created_by' => $request->user()?->id,
            ]);
            $this->fill($project, $validated);
            $project->save();
            $project->forceFill(['unit_key' => 'app-'.$project->id])->save();

            return $project;
        });

        if (! ($validated['start_now'] ?? false)) {
            return back()->with('success', "Project {$project->name} created on port {$port}.");
        }

        return $this->run($project, 'start', "Project {$project->name} created on port {$port} and started.");
    }

    public function update(Request $request, string $token, string $project): RedirectResponse
    {
        $project = $this->project($request, $project);
        $this->fill($project, $request->validate($this->settingsRules()));
        $project->save();

        if ($project->status !== 'running') {
            return back()->with('success', "Project {$project->name} saved.");
        }

        return $this->run($project, 'restart', "Project {$project->name} saved and restarted.");
    }

    public function control(Request $request, string $token, string $project): RedirectResponse
    {
        $validated = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'restart'])]]);
        $project = $this->project($request, $project);
        $past = ['start' => 'started', 'stop' => 'stopped', 'restart' => 'restarted'][$validated['action']];

        return $this->run($project, $validated['action'], "Project {$project->name} {$past}.");
    }

    public function status(Request $request, string $token, string $project): JsonResponse
    {
        $project = $this->project($request, $project);
        try {
            return response()->json(['success' => true, 'data' => $this->process->control($project, 'status')]);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 502);
        }
    }

    public function destroy(Request $request, string $token, string $project, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $project = $this->project($request, $project);
        try {
            $this->process->control($project, 'remove');
        } catch (\Throwable $exception) {
            return back()->with('error', "Could not remove the process of {$project->name}: {$exception->getMessage()}");
        }

        $domains = Website::query()
            ->whereIn('id', $project->shares()->pluck('website_id'))
            ->pluck('domain')
            ->map(fn ($domain): string => (string) $domain)
            ->all();
        DB::transaction(function () use ($project): void {
            $project->shares()->delete();
            $project->delete();
        });
        if ($domains !== []) {
            $reloader->reloadDomains($domains);
        }

        return back()->with('success', "Project {$project->name} deleted.");
    }

    /** @return array<string, mixed> */
    private function settingsRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'working_directory' => ['required', 'string', 'max:400'],
            'entry_file' => ['nullable', 'string', 'max:255'],
            'start_command' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'string', 'regex:/^\d+(\.\d+){0,2}$/'],
            'python_workers' => ['nullable', 'integer', 'between:1,32'],
            'python_mode' => ['nullable', Rule::in(Website::PYTHON_MODES)],
            'python_timeout' => ['nullable', 'integer', 'between:'.Website::MIN_PYTHON_TIMEOUT.','.Website::MAX_PYTHON_TIMEOUT],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function fill(AppProject $project, array $validated): void
    {
        $entry = trim((string) ($validated['entry_file'] ?? ''));
        $command = trim((string) ($validated['start_command'] ?? ''));
        if ($entry === '' && $command === '') {
            throw ValidationException::withMessages([
                'entry_file' => $project->runtime === 'node'
                    ? 'Enter an entry file (e.g. server.js) or a start command.'
                    : 'Enter a WSGI/ASGI app path (e.g. app:app) or a start command.',
            ]);
        }
        if ($entry !== '' && (str_contains($entry, '..') || str_starts_with($entry, '/'))) {
            throw ValidationException::withMessages(['entry_file' => 'The entry must be relative to the working directory.']);
        }

        $python = $project->runtime === 'python';
        $project->fill([
            'name' => trim((string) $validated['name']),
            'working_directory' => $this->workingDirectory($project->site_owner, (string) $validated['working_directory']),
            'entry_file' => $entry !== '' ? $entry : null,
            'start_command' => $command !== '' ? $command : null,
            'version' => $validated['version'] ?? null,
            'python_workers' => $python ? ($validated['python_workers'] ?? null) : null,
            'python_mode' => $python ? ($validated['python_mode'] ?? null) : null,
            'python_timeout' => $python ? ($validated['python_timeout'] ?? null) : null,
        ]);
    }

    /** Accepts "apps/api", "/apps/api" or "/home/{owner}/apps/api"; always inside that home. */
    private function workingDirectory(string $owner, string $input): string
    {
        $home = '/home/'.$owner;
        $path = str_replace('\\', '/', trim($input));
        if ($path === $home || str_starts_with($path, $home.'/')) {
            $path = substr($path, strlen($home));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || ! preg_match('/^[A-Za-z0-9._@+-]+$/', $segment)) {
                throw ValidationException::withMessages(['working_directory' => 'Use a folder inside the home directory, made of letters, digits, dots, dashes and underscores.']);
            }
            $segments[] = $segment;
        }

        return rtrim($home.'/'.implode('/', $segments), '/');
    }

    private function assertPortFree(int $port, ?int $exceptProjectId, LocalPortScanner $scanner): void
    {
        $taken = AppProject::query()
            ->where('port', $port)
            ->when($exceptProjectId, fn ($query) => $query->where('id', '!=', $exceptProjectId))
            ->exists();
        if ($taken || $scanner->ownerOf($port) !== null) {
            throw ValidationException::withMessages(['port' => "Port {$port} is already in use. Leave it empty to get a free one."]);
        }
    }

    private function run(AppProject $project, string $action, string $message): RedirectResponse
    {
        try {
            $this->process->control($project, $action);
        } catch (\Throwable $exception) {
            return back()->with('error', "{$project->name}: {$exception->getMessage()}");
        }

        return back()->with('success', $message);
    }

    private function project(Request $request, string $id): AppProject
    {
        return AppProject::query()->visibleTo($request->user())->findOrFail($id);
    }
}
