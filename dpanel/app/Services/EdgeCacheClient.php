<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Purges and reads the edge gateway's response cache (/__admin/cache). */
class EdgeCacheClient
{
    /**
     * @param  array<int, string>  $urls  full URLs or paths to remove exactly
     * @param  array<int, string>  $prefixes  paths whose copies all go
     * @return int copies removed
     *
     * @throws RuntimeException when the gateway cannot be reached
     */
    public function purge(string $domain, array $urls = [], array $prefixes = []): int
    {
        $response = $this->gateway()->post('/__admin/cache/purge', [
            'domain' => strtolower(trim($domain)),
            'urls' => array_values($urls),
            'prefixes' => array_values($prefixes),
        ]);
        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException((string) ($response->json('message') ?: 'The edge gateway did not purge the cache (HTTP '.$response->status().').'));
        }

        return (int) $response->json('purged', 0);
    }

    /**
     * @return array<string, mixed>|null  null when the gateway is unreachable
     */
    public function stats(string $domain): ?array
    {
        try {
            $response = $this->gateway()->get('/__admin/cache/stats', ['domain' => strtolower(trim($domain))]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! $response->json('success')) {
            return null;
        }
        $data = (array) $response->json('data', []);

        return [
            'domain' => (array) ($data['domains'][strtolower(trim($domain))] ?? []) + [
                'hits' => 0, 'misses' => 0, 'stale' => 0, 'bypass' => 0, 'dynamic' => 0,
                'bytes_served_from_cache' => 0, 'entries' => 0, 'stored_bytes' => 0,
            ],
            'stored_bytes' => (int) ($data['stored_bytes'] ?? 0),
            'max_bytes' => (int) ($data['max_bytes'] ?? 0),
        ];
    }

    private function gateway(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('serverpanel.execution_api_token'))
            ->baseUrl(rtrim((string) config('serverpanel.edge_gateway_internal_url'), '/'))
            ->timeout(10);
    }
}
