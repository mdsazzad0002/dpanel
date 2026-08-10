<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class EdgeGatewayReloader
{
    public function reload(): bool
    {
        try {
            $listeners = (int) Redis::publish((string) config('serverpanel.edge_gateway_reload_channel', 'edge:reload'), json_encode(['resource' => 'websites', 'at' => microtime(true)]));
            if ($listeners > 0) return true;
            Log::notice('No Redis gateway reload listener found; using HTTP fallback.');
        } catch (\Throwable $error) {
            Log::warning('Redis gateway reload publish failed; using HTTP fallback.', ['error' => $error->getMessage()]);
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('serverpanel.execution_api_token'))
                ->timeout(10)
                ->post(rtrim((string) config('serverpanel.edge_gateway_internal_url'), '/').'/__admin/reload');
            $success = $response->successful() && (bool) $response->json('success');
            if (! $success) Log::warning('Edge gateway retained its previous snapshot.', ['response' => $response->json()]);
            return $success;
        } catch (\Throwable $error) {
            Log::warning('Edge gateway reload request failed; previous snapshot remains active.', ['error' => $error->getMessage()]);
            return false;
        }
    }
}
