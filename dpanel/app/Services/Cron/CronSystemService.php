<?php

namespace App\Services\Cron;

use App\Models\CronJob;
use App\Models\Website;
use Illuminate\Support\Facades\Http;

class CronSystemService
{
    /**
     * Panel-owned jobs listed under the root panel website, keyed by job id,
     * with the system user each runs as. The scheduler runs as www-data like
     * the queue workers, so files it writes under storage/ stay writable.
     */
    public const SYSTEM_JOB_USERS = [
        'trash-backups-prune' => 'root',
        'panel-scheduler' => 'www-data',
    ];

    public function sync(CronJob $job, Website $website): string
    {
        return $this->request([
            'action' => 'upsert',
            'id' => (string) $job->id,
            'user' => self::SYSTEM_JOB_USERS[(string) $job->id] ?? (string) $website->site_owner,
            'expression' => (string) $job->expression,
            'command' => (string) $job->command,
            'enabled' => (string) $job->status === 'active',
        ]);
    }

    public function delete(string $jobId): string
    {
        return $this->request([
            'action' => 'delete',
            'id' => $jobId,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('Rust execution API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/cron-job', $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Rust cron API request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust cron API failed.'));
        }

        return (string) ($json['message'] ?? 'System cron updated.');
    }
}
