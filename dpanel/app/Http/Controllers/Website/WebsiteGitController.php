<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\GithubAccount;
use App\Models\GithubOAuthApp;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\Github\GithubClient;
use App\Services\Website\WebsiteGitService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteGitController extends Controller
{
    public function __construct(
        private readonly WebsiteGitService $git,
        private readonly GithubClient $github,
    ) {}

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);
        $deployment = WebsiteGitDeployment::query()->where('website_id', $id)->first();

        return Inertia::render('Websites/GitDeployment', [
            'website' => $website,
            'github' => [
                'configured' => GithubOAuthApp::current() !== null,
                'accounts' => GithubAccount::query()->ownedBy($request->user())->orderBy('login')->get()
                    ->map(fn (GithubAccount $account): array => $account->summary()),
            ],
            ...$this->state($deployment),
        ]);
    }

    /**
     * Deployment state the Git page renders; returned after every operation
     * so the page updates in place without a reload.
     */
    private function state(?WebsiteGitDeployment $deployment): array
    {
        return [
            'deployment' => $deployment ? $this->present($deployment->refresh()) : null,
            'repositoryConnected' => (bool) $deployment?->logs()->where('action', 'clone')->where('status', 'success')->exists(),
            'logs' => $deployment?->logs()->latest()->limit(30)->get() ?? [],
        ];
    }

    public function store(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'repository_url' => ['required', 'url', 'max:2000', 'regex:#^https://(github\.com|gitlab\.com|bitbucket\.org)/#i'],
            'branch' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+$#'],
            'github_account_id' => ['nullable', 'string', 'max:64'],
            'repository_full_name' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
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
        // Users may only attach their own GitHub accounts, but an account
        // already linked by someone else (e.g. the site owner, when an admin
        // edits the site) may be kept as is.
        $accountId = $validated['github_account_id'] ?? null;
        if (filled($accountId) && $accountId !== $existing?->github_account_id
            && ! GithubAccount::query()->ownedBy($request->user())->whereKey($accountId)->exists()) {
            return response()->json(['message' => 'Choose one of your connected GitHub accounts.'], 422);
        }
        if (filled($validated['github_account_id'] ?? null)) {
            if (parse_url($validated['repository_url'], PHP_URL_HOST) !== 'github.com') {
                return response()->json(['message' => 'A connected GitHub account can only deploy github.com repositories.'], 422);
            }
            $validated['repository_full_name'] = $validated['repository_full_name']
                ?? preg_replace('#\.git$#', '', trim((string) parse_url($validated['repository_url'], PHP_URL_PATH), '/'));
            // The account's OAuth token replaces any manually entered token.
            $validated['clear_token'] = true;
        } else {
            $validated['github_account_id'] = null;
        }
        $clearToken = (bool) ($validated['clear_token'] ?? false);
        unset($validated['clear_token']);
        if ($clearToken) {
            $validated['auth_token'] = null;
            $validated['auth_username'] = null;
        } elseif (($validated['auth_token'] ?? '') === '') {
            unset($validated['auth_token']);
        }
        // A different repository or branch is a new enrollment: drop the old
        // clone history so the page offers a fresh deploy instead of pulling
        // through the previous repository's .git remote.
        $repositoryChanged = $existing !== null
            && ($existing->repository_url !== $validated['repository_url'] || $existing->branch !== $validated['branch']);
        if ($repositoryChanged) {
            $existing->logs()->delete();
            $existing->forceFill(['last_synced_at' => null, 'last_status' => null, 'last_message' => null])->save();
        }
        // The push webhook lives on the repository and is owned by the linked
        // account, so moving to another repository or account retires it.
        if ($existing?->github_hook_id && ($existing->repository_url !== $validated['repository_url']
            || $existing->github_account_id !== $validated['github_account_id'])) {
            $this->removeHook($existing);
            $existing->forceFill(['github_hook_id' => null, 'deploy_on_push' => false])->save();
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

        return response()->json([
            'message' => 'Git deployment settings saved.',
            'deployment' => $this->present($deployment->refresh()),
            'repository_changed' => $repositoryChanged,
        ]);
    }

    public function destroy(Request $request, string $token, string $id): JsonResponse
    {
        $this->website($request, $id);
        // Only the connection and its activity are removed; website files stay.
        $deployment = WebsiteGitDeployment::query()->where('website_id', $id)->first();
        if ($deployment) {
            $this->removeHook($deployment);
            $deployment->delete();
        }

        return response()->json(['message' => 'Repository disconnected.']);
    }

    public function run(Request $request, string $token, string $id): JsonResponse
    {
        $this->website($request, $id);
        $validated = $request->validate([
            'action' => ['required', Rule::in(WebsiteGitService::ACTIONS)],
            'message' => ['nullable', 'string', 'max:200'],
            'branch' => ['required_if:action,checkout', 'nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+$#', 'not_regex:#\.\.|^-#'],
        ]);
        $deployment = WebsiteGitDeployment::query()->where('website_id', $id)->firstOrFail();
        try {
            $result = $this->git->run($deployment, $validated['action'], $request->user()?->id, $validated['message'] ?? 'Website update', $validated['branch'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false, ...$this->state($deployment)], 422);
        }

        return response()->json(['message' => $result['output'], 'success' => $result['success'], ...$this->state($deployment)], $result['success'] ? 200 : 422);
    }

    /**
     * Turn push-to-deploy on or off. With a connected GitHub account that
     * administers the repository, the webhook is created on GitHub
     * automatically; otherwise the URL + secret are returned for manual setup.
     */
    public function webhook(Request $request, string $token, string $id): JsonResponse
    {
        $this->website($request, $id);
        $validated = $request->validate(['enabled' => ['required', 'boolean'], 'rotate' => ['boolean']]);
        $deployment = WebsiteGitDeployment::query()->with('githubAccount')->where('website_id', $id)->firstOrFail();

        if (! $validated['enabled']) {
            $this->removeHook($deployment);
            $deployment->forceFill(['deploy_on_push' => false, 'github_hook_id' => null])->save();

            return response()->json(['message' => 'Push-to-deploy disabled.', 'deployment' => $this->present($deployment)]);
        }

        if (! $deployment->webhook_secret || ($validated['rotate'] ?? false)) {
            $this->removeHook($deployment);
            $deployment->forceFill(['webhook_secret' => Str::random(48), 'github_hook_id' => null]);
        }
        $deployment->deploy_on_push = true;
        $message = 'Push-to-deploy enabled. Add the webhook URL and secret to your repository settings.';
        $account = $deployment->githubAccount;
        if ($account && $deployment->repository_full_name && ! $deployment->github_hook_id) {
            try {
                $deployment->github_hook_id = $this->github->createPushHook($account, $deployment->repository_full_name, $this->webhookUrl($deployment), $deployment->webhook_secret);
                $message = "Push-to-deploy enabled. Webhook created on {$deployment->repository_full_name}.";
            } catch (\RuntimeException $e) {
                $message = 'Push-to-deploy enabled, but the webhook could not be created automatically ('.$e->getMessage().'). Add it manually with the URL and secret below.';
            }
        } elseif ($deployment->github_hook_id) {
            $message = 'Push-to-deploy enabled.';
        }
        $deployment->save();

        return response()->json([
            'message' => $message,
            'deployment' => $this->present($deployment),
            // Shown once so it can be pasted into GitHub by hand if needed.
            'webhook_secret' => $deployment->github_hook_id ? null : $deployment->webhook_secret,
        ]);
    }

    private function present(WebsiteGitDeployment $deployment): array
    {
        $account = $deployment->githubAccount;

        return array_merge($deployment->toArray(), [
            'has_token' => filled($deployment->auth_token),
            'github_account' => $account ? ['id' => $account->id, 'login' => $account->login, 'avatar_url' => $account->avatar_url] : null,
            'webhook_url' => $this->webhookUrl($deployment),
        ]);
    }

    private function webhookUrl(WebsiteGitDeployment $deployment): string
    {
        return route('webhooks.git.deploy', ['deployment' => $deployment->id]);
    }

    private function removeHook(WebsiteGitDeployment $deployment): void
    {
        if (! $deployment->github_hook_id || ! $deployment->githubAccount || ! $deployment->repository_full_name) {
            return;
        }
        try {
            $this->github->deleteHook($deployment->githubAccount, $deployment->repository_full_name, (int) $deployment->github_hook_id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
