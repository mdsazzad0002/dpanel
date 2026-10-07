<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Models\WebsiteQueueWorker;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Http;

/**
 * Keeps a Laravel website's queue workers (systemd units managed by drust)
 * in line with its website_queue_workers rows.
 */
class LaravelQueueService
{
    public function __construct(
        protected FilemanagerService $filemanagerService,
        protected WordpressInstallService $wordpressInstallService,
    ) {}

    /** Rewrites, enables and starts the enabled workers; removes the rest. */
    public function sync(Website $website): void
    {
        $workers = $website->queueWorkers()->where('enabled', true)->orderBy('id')->get();
        $payload = ['workers' => $workers->map(fn (WebsiteQueueWorker $worker): array => [
            'id' => (string) $worker->id,
            'connection' => $worker->connection ?: null,
            'queue' => $worker->queue ?: 'default',
            'processes' => $worker->processes,
            'tries' => $worker->tries,
            'timeout' => $worker->timeout,
            'sleep' => $worker->sleep,
            'memory' => $worker->memory,
        ])->values()->all()];

        if ($workers->isNotEmpty()) {
            $payload['site_owner'] = (string) $website->site_owner;
            try {
                $payload['project_root'] = $this->resolveProjectRoot($website);
            } catch (\RuntimeException $e) {
                // Without the app nothing can run; stop every unit so removed
                // or disabled workers do not keep going.
                $this->removeAll($website);

                throw new \RuntimeException($e->getMessage().' All queue workers for this site were stopped.', 0, $e);
            }
            $payload['php_version'] = (string) ($website->php_version ?? '');
        }

        $this->call($website, 'apply', $payload, 120);
    }

    public function restart(Website $website): void
    {
        $this->call($website, 'restart', [], 120);
    }

    /** Stops and deletes every worker unit, e.g. before the website is deleted. */
    public function removeAll(Website $website): void
    {
        $this->call($website, 'remove', [], 120);
    }

    /** @return array<string, list<array{instance: int, active_state: string, enabled: bool}>> instances keyed by worker id */
    public function status(Website $website): array
    {
        $data = $this->call($website, 'status');
        $byWorker = [];
        foreach ((array) ($data['workers'] ?? []) as $worker) {
            $byWorker[(string) ($worker['id'] ?? '')] = array_values((array) ($worker['instances'] ?? []));
        }

        return $byWorker;
    }

    public function logs(Website $website, WebsiteQueueWorker $worker, int $lines = 150): string
    {
        $data = $this->call($website, 'logs', ['worker_id' => (string) $worker->id, 'lines' => $lines]);

        return (string) ($data['output'] ?? '');
    }

    /** The directory holding the site's `artisan`. */
    public function resolveProjectRoot(Website $website): string
    {
        $installRoot = $this->wordpressInstallService->resolveInstallationRoot($website->toArray());
        $candidates = array_values(array_unique(array_filter([
            rtrim((string) $website->root_path, '/'),
            rtrim((string) $website->project_root, '/'),
            $installRoot,
            $installRoot !== '' ? dirname($installRoot) : '',
        ])));

        foreach ($candidates as $candidate) {
            try {
                $inspection = $this->filemanagerService->inspectWebsiteApplication((string) $website->site_owner, $candidate);
            } catch (\Throwable) {
                continue;
            }
            if (strtolower((string) ($inspection['detected_app'] ?? '')) === 'laravel') {
                return rtrim((string) ($inspection['root_path'] ?? $candidate), '/');
            }
        }

        throw new \RuntimeException('No Laravel project (artisan file) was found for this website.');
    }

    /** @return array<string, mixed> */
    protected function call(Website $website, string $action, array $payload = [], int $timeout = 60): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout($timeout);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post(rtrim($baseUrl, '/').'/api/v1/laravel/queue', [
            'site_id' => (string) $website->id,
            'action' => $action,
            ...$payload,
        ]);

        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Queue worker request failed.'));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
