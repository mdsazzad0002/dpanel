<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\AppProject;
use App\Models\PortShare;
use App\Models\Website;
use App\Services\EdgeGatewayReloader;
use App\Services\Projects\LocalPortScanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Port Share: publish a local port on a website path through the edge gateway. */
class PortShareController extends Controller
{
    public function index(Request $request, LocalPortScanner $scanner): Response
    {
        $websites = $this->websites($request)->orderBy('domain')->get(['id', 'domain', 'enable_ssl']);
        $shares = PortShare::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->orderBy('path_prefix')
            ->get()
            ->groupBy('website_id');
        $projects = AppProject::query()->visibleTo($request->user())->orderBy('name')->get();

        return Inertia::render('Projects/PortShares', [
            'websites' => $websites->map(fn (Website $website): array => [
                'id' => (string) $website->id,
                'domain' => (string) $website->domain,
                'enable_ssl' => (bool) $website->enable_ssl,
                'shares' => ($shares->get($website->id) ?? collect())->map->toRow()->values(),
            ])->values(),
            'projects' => $projects->map(fn (AppProject $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'runtime' => $project->runtime,
                'port' => $project->port,
                'status' => $project->status,
            ])->values(),
            'listening' => $this->shareablePorts($request, $scanner),
        ]);
    }

    public function store(Request $request, string $token, LocalPortScanner $scanner, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $validated = $request->validate(['website_id' => ['required', 'string'], ...$this->settingsRules()]);
        $website = $this->website($request, $validated['website_id']);

        $share = new PortShare(['website_id' => (string) $website->id, 'enabled' => true, 'created_by' => $request->user()?->id]);
        $this->fill($request, $share, $validated, $scanner);
        $share->save();

        return $this->applied($reloader, $website, 'Port share created');
    }

    public function update(Request $request, string $token, string $share, LocalPortScanner $scanner, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $share = PortShare::query()->findOrFail($share);
        $website = $this->website($request, $share->website_id);
        $this->fill($request, $share, $request->validate($this->settingsRules()), $scanner);
        $share->save();

        return $this->applied($reloader, $website, 'Port share saved');
    }

    public function toggle(Request $request, string $token, string $share, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $share = PortShare::query()->findOrFail($share);
        $website = $this->website($request, $share->website_id);
        $share->enabled = ! $share->enabled;
        $share->save();

        return $this->applied($reloader, $website, $share->enabled ? 'Port share enabled' : 'Port share disabled');
    }

    public function destroy(Request $request, string $token, string $share, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $share = PortShare::query()->findOrFail($share);
        $website = $this->website($request, $share->website_id);
        $share->delete();

        return $this->applied($reloader, $website, 'Port share deleted');
    }

    /** @return array<string, mixed> */
    private function settingsRules(): array
    {
        return [
            'path_prefix' => ['required', 'string', 'max:200'],
            'source' => ['required', Rule::in(['project', 'port'])],
            'app_project_id' => ['nullable', 'required_if:source,project', 'integer'],
            'target_port' => ['nullable', 'required_if:source,port', 'integer', 'between:1,65535'],
            'strip_prefix' => ['required', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function fill(Request $request, PortShare $share, array $validated, LocalPortScanner $scanner): void
    {
        $path = $this->normalizePath((string) $validated['path_prefix']);
        $duplicate = PortShare::query()
            ->where('website_id', $share->website_id)
            ->where('path_prefix', $path)
            ->when($share->exists, fn ($query) => $query->where('id', '!=', $share->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['path_prefix' => "This website already shares {$path}."]);
        }

        if ($validated['source'] === 'project') {
            $project = AppProject::query()->visibleTo($request->user())->find($validated['app_project_id']);
            if ($project === null) {
                throw ValidationException::withMessages(['app_project_id' => 'Choose one of your projects.']);
            }
            $projectId = $project->id;
            $port = (int) $project->port;
        } else {
            $projectId = null;
            $port = (int) $validated['target_port'];
            $this->assertPortShareable($request, $port, $scanner);
        }

        $share->fill([
            'path_prefix' => $path,
            'target_port' => $port,
            'app_project_id' => $projectId,
            'strip_prefix' => $path !== '/' && (bool) $validated['strip_prefix'],
        ]);
    }

    private function normalizePath(string $input): string
    {
        $path = '/'.trim(trim($input), '/');
        if ($path !== '/' && ! preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) {
            throw ValidationException::withMessages(['path_prefix' => 'Use "/" or a path like /api or /app/v1 (letters, digits, . _ ~ -).']);
        }
        if ($path === '/.well-known' || str_starts_with($path, '/.well-known/')) {
            throw ValidationException::withMessages(['path_prefix' => '/.well-known is reserved for SSL certificate checks.']);
        }

        return $path;
    }

    /**
     * Admins may share any port but the gateway's own; everyone else only a
     * port whose listener runs as one of the Linux users of their websites.
     */
    private function assertPortShareable(Request $request, int $port, LocalPortScanner $scanner): void
    {
        if (in_array($port, $this->reservedPorts(), true)) {
            throw ValidationException::withMessages(['target_port' => "Port {$port} belongs to the panel itself and cannot be shared."]);
        }
        if ($request->user()?->hasRole('admin')) {
            return;
        }

        $owner = $scanner->ownerOf($port);
        if ($owner === null) {
            throw ValidationException::withMessages(['target_port' => "Nothing is listening on port {$port}. Start your app first, then share it."]);
        }
        if (! in_array($owner, AppProject::ownersVisibleTo($request->user()), true)) {
            throw ValidationException::withMessages(['target_port' => "Port {$port} is not run by one of your website users."]);
        }
    }

    /** @return array<int, array{port:int, owner:string}> */
    private function shareablePorts(Request $request, LocalPortScanner $scanner): array
    {
        $admin = (bool) $request->user()?->hasRole('admin');
        $owners = $admin ? [] : AppProject::ownersVisibleTo($request->user());
        $reserved = $this->reservedPorts();
        $rows = [];
        foreach ($scanner->listening() as $port => $owner) {
            if (in_array($port, $reserved, true) || (! $admin && ! in_array($owner, $owners, true))) {
                continue;
            }
            $rows[] = ['port' => $port, 'owner' => $owner];
        }

        return $rows;
    }

    /** @return array<int, int> */
    private function reservedPorts(): array
    {
        $reserved = [80, 443];
        $apiPort = parse_url((string) config('serverpanel.execution_api_base_url', ''), PHP_URL_PORT);
        if (is_int($apiPort)) {
            $reserved[] = $apiPort;
        }

        return $reserved;
    }

    private function applied(EdgeGatewayReloader $reloader, Website $website, string $message): RedirectResponse
    {
        $live = $reloader->reloadDomains([(string) $website->domain]);

        return back()->with(
            $live ? 'success' : 'error',
            $live ? "{$message} and applied." : "{$message}, but the edge gateway did not confirm the reload; it applies on its next reload.",
        );
    }

    private function website(Request $request, string $id): Website
    {
        return $this->websites($request)->findOrFail($id);
    }

    /** The panel's own site is never shared onto from here. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
