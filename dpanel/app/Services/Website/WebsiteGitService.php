<?php

namespace App\Services\Website;

use App\Models\WebsiteGitDeployment;
use App\Models\WebsiteGitLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WebsiteGitService
{
    /** @return array{success: bool, output: string, exit_code: int} */
    public function run(WebsiteGitDeployment $deployment, string $action, ?int $actorId = null, string $message = 'Automated website update'): array
    {
        $action = strtolower($action);
        if (! in_array($action, ['clone', 'status', 'pull', 'push', 'sync'], true)) {
            throw new \InvalidArgumentException('Unsupported Git action.');
        }

        $website = $deployment->website()->firstOrFail();
        $lock = Cache::lock('website-git:'.$website->getKey(), 300);
        if (! $lock->get()) {
            throw new \RuntimeException('Another Git operation is already running for this website.');
        }

        try {
            $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
            $token = trim((string) config('serverpanel.execution_api_token', ''));
            if ($token !== '') $request = $request->withToken($token);
            $response = $request->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/git-deploy', [
                'site_owner' => (string) $website->site_owner,
                'target' => rtrim((string) $website->root_path, '/'),
                'repository' => (string) $deployment->repository_url,
                'branch' => (string) $deployment->branch,
                'action' => $action,
                'message' => mb_substr(trim($message) ?: 'Website update', 0, 200),
                'username' => (string) ($deployment->auth_username ?: 'x-access-token'),
                'token' => (string) ($deployment->auth_token ?: ''),
            ]);
            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $result = [
                'success' => $response->successful() && (bool) ($json['success'] ?? false),
                'output' => (string) ($data['output'] ?? $json['message'] ?? $response->body()),
                'exit_code' => $response->successful() ? 0 : $response->status(),
            ];

            $success = (bool) ($result['success'] ?? false);
            $output = mb_substr(trim((string) ($result['output'] ?? '')), 0, 12000);
            $deployment->forceFill([
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
}
