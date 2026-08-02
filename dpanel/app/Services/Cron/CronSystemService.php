<?php

namespace App\Services\Cron;

use App\Models\CronJob;
use App\Models\Website;
use Illuminate\Support\Facades\Http;

class CronSystemService
{
    public function sync(CronJob $job, Website $website): string
    {
        return $this->request([
            'action' => 'upsert',
            'id' => (string) $job->id,
            'user' => (string) $job->id === 'trash-backups-prune' ? 'root' : (string) $website->site_owner,
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
