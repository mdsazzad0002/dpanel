<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * drust's `GET /system/overview` (backlog D3): host, uptime, load, memory,
 * disk, network and service states. The panel reads none of these itself.
 * `null` when drust does not answer, so a page shows "unknown", not an error.
 */
class SystemOverviewClient
{
    private const CACHE_SECONDS = 10;

    /**
     * @param  list<string>  $services
     * @return array<string, mixed>|null
     */
    public function overview(array $services = []): ?array
    {
        sort($services);
        $key = 'system.overview.'.md5(implode(',', $services));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->fetch($services));
    }

    /** @param  list<string>  $services */
    private function fetch(array $services): ?array
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()->timeout(5)
                ->withToken(trim((string) config('dpanel.execution_api_token', '')))
                ->get(rtrim($baseUrl, '/').'/system/overview', ['services' => implode(',', $services)]);
        } catch (\Throwable) {
            return null;
        }

        $json = $response->json();

        return $response->successful() && is_array($json['overview'] ?? null) ? $json['overview'] : null;
    }
}
