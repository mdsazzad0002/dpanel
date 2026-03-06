<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RedisCacheController extends Controller
{
    public function index(string $id): Response
    {
        $website = $this->findWebsiteById($id);
        abort_if($website === null, 404);

        $prefix = $this->buildWebsiteRedisPrefix($website);
        $stats = $this->collectPrefixStats($prefix);

        return Inertia::render('Websites/RedisCache', [
            'website' => $website,
            'redisCache' => [
                'prefix' => $prefix,
                'host' => (string) config('database.redis.default.host', '127.0.0.1'),
                'port' => (int) config('database.redis.default.port', 6379),
                'database' => (int) config('database.redis.default.database', 0),
                'key_count' => $stats['key_count'],
                'sample_keys' => $stats['sample_keys'],
            ],
        ]);
    }

    public function clearWebsiteCache(string $id): RedirectResponse
    {
        $website = $this->findWebsiteById($id);
        if ($website === null) {
            return redirect()->route('websites.list')->with('error', 'Website not found.');
        }

        $prefix = $this->buildWebsiteRedisPrefix($website);
        $keys = $this->safeRedisKeys($prefix.'*');
        $deleted = $this->deleteRedisKeys($keys);

        return redirect()
            ->route('websites.redis-cache.index', $id)
            ->with('success', "Redis cache cleared for {$website->domain}. Deleted keys: {$deleted}");
    }

    /**
     * @return array{key_count:int,sample_keys:array<int,string>}
     */
    private function collectPrefixStats(string $prefix): array
    {
        $keys = $this->safeRedisKeys($prefix.'*');
        $sample = array_slice($keys, 0, 25);

        return [
            'key_count' => count($keys),
            'sample_keys' => $sample,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function safeRedisKeys(string $pattern): array
    {
        try {
            $keys = Redis::connection()->keys($pattern);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_map('strval', $keys));
    }

    /**
     * @param array<int,string> $keys
     */
    private function deleteRedisKeys(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($keys, 500) as $chunk) {
            try {
                $result = Redis::connection()->del($chunk);
                $deleted += is_numeric($result) ? (int) $result : 0;
            } catch (\Throwable $e) {
                // Continue attempting next chunks for best-effort cleanup.
            }
        }

        return $deleted;
    }

    private function buildWebsiteRedisPrefix(Website|array $website): string
    {
        $id = $website instanceof Website ? (string) ($website->id ?? 'site') : (string) ($website['id'] ?? 'site');
        $domainValue = $website instanceof Website ? (string) ($website->domain ?? 'site') : (string) ($website['domain'] ?? 'site');
        $domain = strtolower($domainValue);
        $domain = preg_replace('/[^a-z0-9]+/', '_', $domain) ?? 'site';
        $domain = trim($domain, '_');
        $domain = $domain !== '' ? $domain : 'site';

        return "sp_{$domain}_{$id}_";
    }

    private function findWebsiteById(string $id): ?Website
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return null;
            }

            return Website::query()->find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
