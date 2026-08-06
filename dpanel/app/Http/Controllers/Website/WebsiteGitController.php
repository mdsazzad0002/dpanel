<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\Website\WebsiteGitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteGitController extends Controller
{
    public function __construct(
        private readonly WebsiteGitService $git,
    ) {}

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);
        $deployment = WebsiteGitDeployment::query()->where('website_id', $id)->first();

        return Inertia::render('Websites/GitDeployment', [
            'website' => $website,
            'deployment' => $deployment ? array_merge($deployment->toArray(), ['has_token' => filled($deployment->auth_token)]) : null,
            'repositoryConnected' => (bool) $deployment?->logs()->where('action', 'clone')->where('status', 'success')->exists(),
            'logs' => $deployment?->logs()->latest()->limit(30)->get() ?? [],
        ]);
    }

    public function store(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'repository_url' => ['required', 'url', 'max:2000', 'regex:#^https://(github\.com|gitlab\.com|bitbucket\.org)/#i'],
            'branch' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+$#'],
            'auth_username' => ['nullable', 'string', 'max:255'],
            'auth_token' => ['nullable', 'string', 'max:2000'],
            'auto_action' => ['required', 'in:off,pull,push,sync'],
            'interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'enabled' => ['boolean'],
            'clear_token' => ['boolean'],
        ]);
        if (filled(parse_url($validated['repository_url'], PHP_URL_USER)) || filled(parse_url($validated['repository_url'], PHP_URL_PASS))) {
            return response()->json(['message' => 'Do not put credentials in the repository URL; use the encrypted token field.'], 422);
        }
        $existing = WebsiteGitDeployment::query()->where('website_id', $id)->first();
        $clearToken = (bool) ($validated['clear_token'] ?? false);
        unset($validated['clear_token']);
        if ($clearToken) {
            $validated['auth_token'] = null;
            $validated['auth_username'] = null;
        } elseif (($validated['auth_token'] ?? '') === '') {
            unset($validated['auth_token']);
        }
        $deployment = WebsiteGitDeployment::query()->updateOrCreate(
            ['website_id' => $id],
            array_merge($validated, [
                'provider' => parse_url($validated['repository_url'], PHP_URL_HOST) === 'github.com' ? 'github' : 'git',
                'enabled' => (bool) ($validated['enabled'] ?? true),
                'next_sync_at' => $validated['auto_action'] === 'off' ? null : now()->addMinutes((int) $validated['interval_minutes']),
                'created_by' => $existing?->created_by ?? $request->user()?->id,
            ]),
        );

        return response()->json(['message' => 'Git deployment settings saved.', 'deployment' => array_merge($deployment->toArray(), ['has_token' => filled($deployment->auth_token)])]);
    }

    public function run(Request $request, string $token, string $id): JsonResponse
    {
        $this->website($request, $id);
        $validated = $request->validate(['action' => ['required', 'in:clone,status,pull,push,sync'], 'message' => ['nullable', 'string', 'max:200']]);
        $deployment = WebsiteGitDeployment::query()->where('website_id', $id)->firstOrFail();
        $result = $this->git->run($deployment, $validated['action'], $request->user()?->id, $validated['message'] ?? 'Website update');

        return response()->json(['message' => $result['output'], 'success' => $result['success']], $result['success'] ? 200 : 422);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
