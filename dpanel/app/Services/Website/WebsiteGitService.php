<?php

namespace App\Services\Website;

use App\Models\WebsiteGitDeployment;
use App\Models\WebsiteGitLog;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\Github\GithubClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WebsiteGitService
{
    // init: publish the current website root to an empty repository.
    public const ACTIONS = ['clone', 'init', 'status', 'pull', 'push', 'sync', 'checkout'];

    /** Actions after which the website root is linked to the repository. */
    // "detect" connects a checkout that was already in the website root.
    public const CONNECTING_ACTIONS = ['clone', 'init', 'detect'];

    public function __construct(private PreOverwriteBackupService $preOverwriteBackup, private GithubClient $github)
    {
    }

    /** How a conflict can be settled (see drust git_deploy/conflicts.rs). */
    public const STRATEGIES = ['live', 'remote', 'both', 'custom'];

    /** @return array{success: bool, output: string, exit_code: int} */
    public function run(WebsiteGitDeployment $deployment, string $action, ?int $actorId = null, string $message = 'Automated website update', ?string $branch = null): array
    {
        $action = strtolower($action);
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Git action.');
        }

        return $this->perform($deployment, $action, $actorId, $message, $branch);
    }

    /**
     * True when a failed pull/push/sync stopped because the live site and the
     * repository both changed — the page then offers conflict resolution.
     */
    public static function needsResolution(?string $message): bool
    {
        $message = (string) $message;

        return str_contains($message, 'history diverged')
            || str_contains($message, 'both changed')
            || str_contains($message, 'Remote contains changes not present locally')
            || str_contains($message, 'uncommitted changes; pull stopped')
            || str_contains($message, 'changed the same lines');
    }

    /**
     * Read-only: what the repository has that the site lacks and the files
     * whose changes collide, with base / live / remote versions.
     *
     * @return array{behind: bool, ahead: bool, dirty: bool, conflicts: array<int, array<string, mixed>>}
     */
    public function conflicts(WebsiteGitDeployment $deployment): array
    {
        $website = $deployment->website()->firstOrFail();
        [$username, $secret] = $this->credentials($deployment);
        $result = $this->callDrust($website, (string) $deployment->repository_url, (string) $deployment->branch, 'conflicts', $username, $secret, '');
        $state = $result['success'] ? json_decode($result['output'], true) : null;
        if (! is_array($state)) {
            throw new \RuntimeException($result['output'] ?: 'Could not compare the site with the repository.');
        }

        return [
            'behind' => (bool) ($state['behind'] ?? false),
            'ahead' => (bool) ($state['ahead'] ?? false),
            'dirty' => (bool) ($state['dirty'] ?? false),
            'conflicts' => array_values((array) ($state['conflicts'] ?? [])),
        ];
    }

    /**
     * Merge the repository into the live site, settling conflicts by
     * $strategy, on the server only: nothing is pushed until
     * confirmResolution(). custom takes $resolutions: path => {take: live|remote|both} or {content}.
     *
     * @param  array<string, array{take?: string, content?: string}>  $resolutions
     * @return array{success: bool, output: string, exit_code: int}
     */
    public function resolve(WebsiteGitDeployment $deployment, string $strategy, array $resolutions = [], ?int $actorId = null): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException('Choose live, remote, both, or custom.');
        }

        return $this->perform($deployment, 'resolve', $actorId, 'Resolve conflicts', null, [
            'strategy' => $strategy,
            'resolutions' => (object) $resolutions,
        ]);
    }

    /**
     * The resolved merge waiting for its push: commits and files the
     * repository does not have yet.
     *
     * @return array{pending: bool, commits?: array<int, string>, files?: array<int, array{status: string, path: string}>, summary?: string}
     */
    public function pendingResolution(WebsiteGitDeployment $deployment): array
    {
        $website = $deployment->website()->firstOrFail();
        [$username, $secret] = $this->credentials($deployment);
        $result = $this->callDrust($website, (string) $deployment->repository_url, (string) $deployment->branch, 'pending', $username, $secret, '');
        $state = $result['success'] ? json_decode($result['output'], true) : null;
        if (! is_array($state)) {
            throw new \RuntimeException($result['output'] ?: 'Could not read the resolved merge.');
        }

        return $state;
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    public function confirmResolution(WebsiteGitDeployment $deployment, ?int $actorId = null): array
    {
        return $this->perform($deployment, 'confirm_push', $actorId, 'Push resolved merge', null);
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    public function undoResolution(WebsiteGitDeployment $deployment, ?int $actorId = null): array
    {
        return $this->perform($deployment, 'undo_resolve', $actorId, 'Undo resolved merge', null);
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    private function perform(WebsiteGitDeployment $deployment, string $action, ?int $actorId, string $message, ?string $branch, array $extra = []): array
    {
        // Only checkout moves the working tree to another branch; every other
        // action runs against the deployment's saved branch.
        $branch = $action === 'checkout' ? (string) $branch : (string) $deployment->branch;
        if ($branch === '') {
            throw new \InvalidArgumentException('Choose a branch to check out.');
        }

        $website = $deployment->website()->firstOrFail();
        $lock = Cache::lock('website-git:'.$website->getKey(), 300);
        if (! $lock->get()) {
            throw new \RuntimeException('Another Git operation is already running for this website.');
        }

        try {
            // A fresh git clone onto an existing document root would otherwise
            // just be rejected by dRust ("Target folder is not empty."). Move
            // whatever is there into the File Manager trash first, the same
            // safety net Clone/Import already rely on, so a clone deploy never
            // silently destroys files and is always restorable.
            if ($action === 'clone') {
                $this->preOverwriteBackup->snapshot($website, 'git_clone');
            }
            [$username, $secret] = $this->credentials($deployment);
            if ($secret === '' && in_array($action, ['init', 'push', 'sync', 'confirm_push'], true)) {
                throw new \RuntimeException('Pushing needs credentials. Select a connected GitHub account (or add an access token) in the connection settings and save, or ask an administrator to set a global GitHub account.');
            }
            $result = $this->callDrust($website, (string) $deployment->repository_url, $branch, $action, $username, $secret, $message, $extra);

            $success = (bool) ($result['success'] ?? false);
            $output = mb_substr(trim((string) ($result['output'] ?? '')), 0, 12000);
            $deployment->forceFill([
                'branch' => $success ? $branch : $deployment->branch,
                'last_synced_at' => now(),
                'last_status' => $success ? 'success' : 'failed',
                'last_message' => $output ?: ($success ? 'Operation completed.' : 'Operation failed.'),
                'next_sync_at' => $deployment->auto_action === 'off' ? null : now()->addMinutes($deployment->interval_minutes),
            ])->save();
            WebsiteGitLog::query()->create([
                'deployment_id' => $deployment->id,
                'action' => $action,
                'status' => $success ? 'success' : 'failed',
                'message' => $deployment->last_message,
                'triggered_by' => $actorId,
            ]);

            return ['success' => $success, 'output' => $deployment->last_message, 'exit_code' => (int) ($result['exit_code'] ?? 1)];
        } finally {
            $lock->release();
        }
    }

    /**
     * Decide the first action for a repository before anything runs: a
     * remote with commits is deployed (clone), an empty one is fed from the
     * website's current files (init).
     *
     * @return array{remote_empty: bool, branch_exists: bool, local_files: bool, local_repo: bool, recommended: string}
     */
    public function probe(\App\Models\Website $website, string $repositoryUrl, string $branch, string $username, string $secret): array
    {
        $result = $this->callDrust($website, $repositoryUrl, $branch, 'probe', $username, $secret, '');
        $state = $result['success'] ? json_decode($result['output'], true) : null;
        if (! is_array($state)) {
            throw new \RuntimeException($result['output'] ?: 'Could not inspect the repository.');
        }
        $state = [
            'remote_empty' => (bool) ($state['remote_empty'] ?? false),
            'branch_exists' => (bool) ($state['branch_exists'] ?? false),
            'local_files' => (bool) ($state['local_files'] ?? false),
            'local_repo' => (bool) ($state['local_repo'] ?? false),
        ];
        $state['recommended'] = ! $state['remote_empty'] ? 'clone' : ($state['local_files'] && ! $state['local_repo'] ? 'init' : 'none');

        return $state;
    }

    /**
     * "For Local Development" helpers for a developer editing the site's files
     * as their own user: trust (Git safe.directory for every user),
     * ignore_file_mode (core.fileMode=false) or open_permissions (chmod 777).
     *
     * @return array{success: bool, message: string, paths: array<int, string>}
     */
    public function localDevelopment(\App\Models\Website $website, string $action): array
    {
        $result = $this->callLocalDev($website, $action);

        return ['success' => $result['success'], 'message' => $result['message'], 'paths' => array_values((array) ($result['data']['paths'] ?? []))];
    }

    /**
     * Connect a Git checkout that is already in the website root (copied in
     * by a clone, or made by hand) to the Git page, reading its origin and
     * branch instead of asking for them. A connected GitHub account whose
     * login owns the repository is attached; otherwise the page asks for a
     * token before the first pull/push.
     *
     * @return array{success: bool, message: string, deployment?: WebsiteGitDeployment}
     */
    public function detect(\App\Models\Website $website, \App\Models\User $actor): array
    {
        if ($existing = WebsiteGitDeployment::query()->where('website_id', $website->id)->first()) {
            return ['success' => true, 'message' => 'Git is already connected.', 'deployment' => $existing];
        }
        $result = $this->callLocalDev($website, 'detect');
        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message'] ?: 'No Git repository was found.'];
        }
        $path = rtrim((string) ($result['data']['path'] ?? ''), '/');
        if ($path !== rtrim((string) $website->root_path, '/')) {
            return ['success' => false, 'message' => "The repository is in {$path}, but the Git page manages {$website->root_path}."];
        }
        $url = self::httpsRepositoryUrl((string) ($result['data']['remote'] ?? ''));
        if ($url === null) {
            return ['success' => false, 'message' => 'The origin remote is not an HTTPS GitHub, GitLab or Bitbucket repository: '.($result['data']['remote'] ?? '')];
        }
        $branch = (string) ($result['data']['branch'] ?? '');
        if ($branch === '' || $branch === 'HEAD' || preg_match('#^[A-Za-z0-9._/-]+$#', $branch) !== 1) {
            return ['success' => false, 'message' => 'The checkout is not on a branch.'];
        }

        $fullName = preg_replace('#\.git$#', '', trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $account = parse_url($url, PHP_URL_HOST) === 'github.com'
            ? \App\Models\GithubAccount::query()->ownedBy($actor)->whereRaw('LOWER(login) = ?', [strtolower(explode('/', $fullName)[0])])->first()
            : null;

        $deployment = WebsiteGitDeployment::query()->create([
            'website_id' => $website->id,
            'repository_url' => $url,
            'repository_full_name' => $account ? $fullName : null,
            'branch' => $branch,
            'provider' => parse_url($url, PHP_URL_HOST) === 'github.com' ? 'github' : 'git',
            'github_account_id' => $account?->id,
            'auto_action' => 'off',
            'interval_minutes' => 60,
            'enabled' => true,
            'created_by' => $actor->id,
        ]);
        $deployment->logs()->create([
            'action' => 'detect', 'status' => 'success', 'triggered_by' => $actor->id,
            'message' => "Connected the existing checkout: {$url} ({$branch}).",
        ]);

        return [
            'success' => true,
            'message' => "Git connected: {$fullName} ({$branch})".($account ? " via @{$account->login}." : ' — add a token on the Git page before pulling or pushing.'),
            'deployment' => $deployment,
        ];
    }

    /**
     * The checkout already in the website root, for the Git page to offer
     * connecting it; null when there is none or it can't be read.
     *
     * @return ?array{repository_url: ?string, remote: string, branch: string}
     */
    public function detectedCheckout(\App\Models\Website $website): ?array
    {
        try {
            $result = $this->callLocalDev($website, 'detect');
        } catch (\Throwable) {
            return null;
        }
        if (! $result['success'] || rtrim((string) ($result['data']['path'] ?? ''), '/') !== rtrim((string) $website->root_path, '/')) {
            return null;
        }
        $remote = (string) ($result['data']['remote'] ?? '');

        return ['repository_url' => self::httpsRepositoryUrl($remote), 'remote' => $remote, 'branch' => (string) ($result['data']['branch'] ?? '')];
    }

    /** git@host:owner/repo.git or https://user@host/owner/repo → https://host/owner/repo.git; null for other hosts. */
    public static function httpsRepositoryUrl(string $remote): ?string
    {
        $remote = trim($remote);
        if (preg_match('#^(?:ssh://)?git@(github\.com|gitlab\.com|bitbucket\.org)[:/](.+?)/?$#i', $remote, $m)) {
            $host = strtolower($m[1]);
            $path = $m[2];
        } elseif (preg_match('#^https://(?:[^@/]+@)?(github\.com|gitlab\.com|bitbucket\.org)/(.+?)/?$#i', $remote, $m)) {
            $host = strtolower($m[1]);
            $path = $m[2];
        } else {
            return null;
        }
        $path = preg_replace('#\.git$#', '', $path);

        return preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+$#', $path) === 1 ? "https://{$host}/{$path}.git" : null;
    }

    /** @return array{success: bool, message: string, data: array<string, mixed>} */
    private function callLocalDev(\App\Models\Website $website, string $action): array
    {
        $paths = array_values(array_unique(array_filter([
            rtrim((string) $website->root_path, '/'),
            rtrim((string) $website->project_root, '/'),
        ])));

        $request = Http::acceptJson()->asJson()->timeout(max((int) config('serverpanel.execution_api_timeout', 60), 300));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') $request = $request->withToken($token);
        $response = $request->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/local-dev', [
            'action' => $action,
            'site_owner' => (string) $website->site_owner,
            'paths' => $paths,
        ]);
        $json = $response->json();

        return [
            'success' => $response->successful() && (bool) ($json['success'] ?? false),
            'message' => (string) ($json['message'] ?? $response->body()),
            'data' => (array) ($json['data'] ?? []),
        ];
    }

    /** @return array{0: string, 1: string} */
    public function credentialsFor(WebsiteGitDeployment $deployment): array
    {
        return $this->credentials($deployment);
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    private function callDrust(\App\Models\Website $website, string $repositoryUrl, string $branch, string $action, string $username, string $secret, string $message, array $extra = []): array
    {
        // init pushes everything; conflicts/resolve may first fetch the full history.
        $slow = in_array($action, ['init', 'conflicts', 'resolve', 'confirm_push'], true);
        $request = Http::acceptJson()->asJson()->timeout(max((int) config('serverpanel.execution_api_timeout', 60), $slow ? 300 : 0));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') $request = $request->withToken($token);
        $response = $request->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/git-deploy', [
            'site_owner' => (string) $website->site_owner,
            'target' => rtrim((string) $website->root_path, '/'),
            'repository' => $repositoryUrl,
            'branch' => $branch,
            'action' => $action,
            'message' => mb_substr(trim($message) ?: 'Website update', 0, 200),
            'username' => $username,
            'token' => $secret,
        ] + $extra);
        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return [
            'success' => $response->successful() && (bool) ($json['success'] ?? false),
            'output' => (string) ($data['output'] ?? $json['message'] ?? $response->body()),
            'exit_code' => $response->successful() ? 0 : $response->status(),
        ];
    }

    /**
     * A deployment linked to a connected GitHub account authenticates with
     * that account's OAuth token (refreshed on demand); otherwise it falls
     * back to the manually entered username + access token, and without
     * one to the admin's global default account.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(WebsiteGitDeployment $deployment): array
    {
        $account = $deployment->githubAccount;
        if ($account) {
            return [$account->login, $this->github->tokenFor($account)];
        }
        if (filled($deployment->auth_token)) {
            return [(string) ($deployment->auth_username ?: 'x-access-token'), (string) $deployment->auth_token];
        }
        $global = \App\Models\GithubAccount::global();
        if ($global) {
            return [$global->login, $this->github->tokenFor($global)];
        }

        return [(string) ($deployment->auth_username ?: 'x-access-token'), ''];
    }
}
