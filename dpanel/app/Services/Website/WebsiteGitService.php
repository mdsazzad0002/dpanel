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
    public const CONNECTING_ACTIONS = ['clone', 'init'];

    public function __construct(private PreOverwriteBackupService $preOverwriteBackup, private GithubClient $github)
    {
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    public function run(WebsiteGitDeployment $deployment, string $action, ?int $actorId = null, string $message = 'Automated website update', ?string $branch = null): array
    {
        $action = strtolower($action);
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Git action.');
        }
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
            if ($secret === '' && in_array($action, ['init', 'push', 'sync'], true)) {
                throw new \RuntimeException('Pushing needs credentials. Select a connected GitHub account (or add an access token) in the connection settings and save.');
            }
            $result = $this->callDrust($website, (string) $deployment->repository_url, $branch, $action, $username, $secret, $message);

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

    /** @return array{0: string, 1: string} */
    public function credentialsFor(WebsiteGitDeployment $deployment): array
    {
        return $this->credentials($deployment);
    }

    /** @return array{success: bool, output: string, exit_code: int} */
    private function callDrust(\App\Models\Website $website, string $repositoryUrl, string $branch, string $action, string $username, string $secret, string $message): array
    {
        $request = Http::acceptJson()->asJson()->timeout(max((int) config('serverpanel.execution_api_timeout', 60), $action === 'init' ? 300 : 0));
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
        ]);
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
     * back to the manually entered username + access token.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(WebsiteGitDeployment $deployment): array
    {
        $account = $deployment->githubAccount;
        if ($account) {
            return [$account->login, $this->github->tokenFor($account)];
        }

        return [(string) ($deployment->auth_username ?: 'x-access-token'), (string) ($deployment->auth_token ?: '')];
    }
}
