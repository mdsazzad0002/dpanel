<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class EdgeGatewayReloader
{
    /** @param array<int, string> $domains */
    public function reloadDomains(array $domains): bool
    {
        $domains = array_values(array_unique(array_filter(array_map(
            static fn ($domain): string => strtolower(trim((string) $domain)),
            $domains,
        ))));
        if ($domains === []) {
            return true;
        }

        return $this->dispatchReload(['resource' => 'website', 'domains' => $domains, 'at' => microtime(true)]);
    }

    public function reload(): bool
    {
        return $this->dispatchReload(['resource' => 'websites', 'at' => microtime(true)]);
    }

    /** @param array<string, mixed> $payload */
    private function dispatchReload(array $payload): bool
    {
        try {
            $listeners = (int) Redis::publish(
                (string) config('serverpanel.edge_gateway_reload_channel', 'edge:reload'),
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
            if ($listeners > 0) {
                return true;
            }
            Log::notice('No Redis gateway reload listener found; using HTTP fallback.');
        } catch (\Throwable $error) {
            Log::warning('Redis gateway reload publish failed; using HTTP fallback.', ['error' => $error->getMessage()]);
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('serverpanel.execution_api_token'))
                ->timeout(10)
                ->post(rtrim((string) config('serverpanel.edge_gateway_internal_url'), '/').'/__admin/reload', $payload);
            $success = $response->successful() && (bool) $response->json('success');
            if (! $success) {
                Log::warning('Edge gateway retained its previous snapshot.', ['response' => $response->json()]);
            }

            return $success;
        } catch (\Throwable $error) {
            Log::warning('Edge gateway reload request failed; previous snapshot remains active.', ['error' => $error->getMessage()]);

            return false;
        }
    }
}
