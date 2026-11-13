<?php

namespace App\Services\Platform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * drust's `/resource-limits/{group}` (backlog P4, spec section 8): an
 * account's CPU, memory and process limit, a systemd slice its site users'
 * PHP runs in.
 */
class ResourceLimitsClient
{
    /**
     * @param  list<string>  $owners  the account's site users
     */
    public function set(string $group, int $cpuPercent, int $memoryMb, int $maxProcesses, array $owners): void
    {
        $this->check($this->request()->put($this->url($group), [
            'cpu_percent' => max(0, $cpuPercent),
            'memory_mb' => max(0, $memoryMb),
            'max_processes' => max(0, $maxProcesses),
            'owners' => array_values($owners),
        ]), $group);
    }

    public function clear(string $group): void
    {
        $this->check($this->request()->delete($this->url($group)), $group);
    }

    /** @return array{cpu_percent: float, memory_mb: int, processes: int} */
    public function usage(string $group): array
    {
        $json = $this->check($this->request()->get($this->url($group).'/usage'), $group);

        return [
            'cpu_percent' => (float) ($json['cpu_percent'] ?? 0),
            'memory_mb' => (int) ($json['memory_mb'] ?? 0),
            'processes' => (int) ($json['processes'] ?? 0),
        ];
    }

    private function request(): PendingRequest
    {
        // Moving PHP between masters reloads PHP-FPM, which can take a while.
        return Http::acceptJson()->asJson()
            ->timeout(max(60, (int) config('dpanel.execution_api_timeout', 60)))
            ->withToken(trim((string) config('dpanel.execution_api_token', '')));
    }

    private function url(string $group): string
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/').'/resource-limits/'.rawurlencode($group);
    }

    /** @return array<string, mixed> */
    private function check(Response $response, string $group): array
    {
        $json = $response->json();
        if ($response->status() === 501) {
            throw new UnsupportedLimit('CPU and memory limits are not available on this server.');
        }
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException("Account {$group}: ".((string) (is_array($json) ? ($json['message'] ?? '') : '') ?: $response->body()));
        }

        return $json;
    }
}
