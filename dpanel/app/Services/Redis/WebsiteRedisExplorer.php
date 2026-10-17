<?php

namespace App\Services\Redis;

use App\Models\Website;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Throwable;

/**
 * Read/inspect the Redis namespace that belongs to one website.
 *
 * Every public method takes a key *name relative to the website prefix*, so a
 * caller can never address a key outside that website's namespace.
 */
class WebsiteRedisExplorer
{
    public const STRING_PREVIEW_BYTES = 524288;
    public const MEMBER_PREVIEW_BYTES = 4096;
    public const COLLECTION_PAGE = 100;
    public const STATS_KEY_LIMIT = 20000;

    private const TYPES = [0 => 'none', 1 => 'string', 2 => 'set', 3 => 'list', 4 => 'zset', 5 => 'hash', 6 => 'stream'];

    public function prefixFor(Website|array $website): string
    {
        $id = $website instanceof Website ? (string) ($website->id ?? 'site') : (string) ($website['id'] ?? 'site');
        $domainValue = $website instanceof Website ? (string) ($website->domain ?? 'site') : (string) ($website['domain'] ?? 'site');
        $domain = preg_replace('/[^a-z0-9]+/', '_', strtolower($domainValue)) ?? 'site';
        $domain = trim($domain, '_');
        $domain = $domain !== '' ? $domain : 'site';

        return "sp_{$domain}_{$id}_";
    }

    public function connectionName(): string
    {
        return (string) config('app.website_redis_connection', 'website_cache');
    }

    /**
     * One page of keys. `$search` without wildcards is a substring match;
     * with `*` / `?` it is used as a glob relative to the prefix.
     *
     * @return array{cursor:string,keys:array<int,array{name:string,type:string,ttl:int}>}
     */
    public function scanKeys(string $prefix, string $search = '', string $cursor = '0', int $count = 100): array
    {
        $client = $this->connection()->client();
        $pattern = $this->escapeGlob($prefix).$this->searchPattern($search);
        // phpredis treats an iterator of 0 as "finished", so a fresh scan starts from null.
        $iterator = $cursor === '0' ? null : (int) $cursor;
        $names = [];
        $rounds = 0;

        do {
            $batch = $client->scan($iterator, $pattern, 1000);
            foreach ((array) $batch as $key) {
                $names[] = (string) $key;
            }
        } while ($iterator && count($names) < $count && ++$rounds < 50);

        $cursor = (string) ($iterator ?: '0');
        $names = array_values(array_unique($names));
        sort($names);

        return ['cursor' => $cursor, 'keys' => $this->describeKeys($prefix, $names)];
    }

    /**
     * Key count and approximate memory for the whole namespace.
     *
     * @return array{key_count:int,memory_bytes:int,complete:bool,types:array<string,int>}
     */
    public function namespaceStats(string $prefix): array
    {
        $client = $this->connection()->client();
        $pattern = $this->escapeGlob($prefix).'*';
        $iterator = null;
        $count = 0;
        $memory = 0;
        $types = [];
        $complete = true;

        do {
            $batch = array_values(array_map('strval', (array) $client->scan($iterator, $pattern, 1000)));
            if ($batch === []) {
                continue;
            }
            $count += count($batch);

            $pipe = $client->multi(\Redis::PIPELINE);
            foreach ($batch as $key) {
                $pipe->type($key);
                $pipe->rawCommand('MEMORY', 'USAGE', $key);
            }
            $replies = (array) $pipe->exec();
            foreach (array_chunk($replies, 2) as [$type, $bytes]) {
                $name = self::TYPES[(int) $type] ?? 'unknown';
                $types[$name] = ($types[$name] ?? 0) + 1;
                $memory += is_numeric($bytes) ? (int) $bytes : 0;
            }

            if ($count >= self::STATS_KEY_LIMIT) {
                $complete = ! $iterator;
                break;
            }
        } while ($iterator);

        arsort($types);

        return ['key_count' => $count, 'memory_bytes' => $memory, 'complete' => $complete, 'types' => $types];
    }

    /**
     * Full detail of one key. `$cursor` pages through hash/set/list/zset members.
     *
     * @return array<string,mixed>
     */
    public function inspect(string $prefix, string $name, string $cursor = '0'): array
    {
        $key = $this->fullKey($prefix, $name);
        $client = $this->connection()->client();
        $type = self::TYPES[(int) $client->type($key)] ?? 'unknown';
        if ($type === 'none') {
            throw new InvalidArgumentException('Key not found. It may have expired.');
        }

        $detail = [
            'name' => $name,
            'key' => $key,
            'type' => $type,
            'ttl' => (int) $client->ttl($key),
            'memory_bytes' => $this->memoryUsage($client, $key),
            'encoding' => $this->safe(fn () => $client->rawCommand('OBJECT', 'ENCODING', $key)),
            'size' => 0,
            'next_cursor' => '0',
        ];

        switch ($type) {
            case 'string':
                $length = (int) $client->strlen($key);
                $raw = (string) $client->getRange($key, 0, self::STRING_PREVIEW_BYTES - 1);
                $detail['size'] = $length;
                $detail['truncated'] = $length > self::STRING_PREVIEW_BYTES;
                $detail['value'] = $this->presentValue($raw);
                $detail['editable'] = ! $detail['truncated'] && $detail['value']['binary'] === false;
                break;

            case 'hash':
                $detail['size'] = (int) $client->hLen($key);
                [$next, $data] = $this->scanCollection($client, 'hScan', $key, $cursor);
                $detail['next_cursor'] = $next;
                $detail['items'] = collect($data)->map(fn ($value, $field) => [
                    'field' => $this->clip((string) $field),
                    'value' => $this->presentValue((string) $value, self::MEMBER_PREVIEW_BYTES),
                ])->values()->all();
                break;

            case 'set':
                $detail['size'] = (int) $client->sCard($key);
                [$next, $data] = $this->scanCollection($client, 'sScan', $key, $cursor);
                $detail['next_cursor'] = $next;
                $detail['items'] = array_map(fn ($member) => ['value' => $this->presentValue((string) $member, self::MEMBER_PREVIEW_BYTES)], array_values($data));
                break;

            case 'list':
                $detail['size'] = (int) $client->lLen($key);
                $offset = max(0, (int) $cursor);
                $data = (array) $client->lRange($key, $offset, $offset + self::COLLECTION_PAGE - 1);
                $detail['next_cursor'] = $offset + self::COLLECTION_PAGE < $detail['size'] ? (string) ($offset + self::COLLECTION_PAGE) : '0';
                $detail['items'] = array_map(fn ($value, $i) => ['index' => $offset + $i, 'value' => $this->presentValue((string) $value, self::MEMBER_PREVIEW_BYTES)], $data, array_keys($data));
                break;

            case 'zset':
                $detail['size'] = (int) $client->zCard($key);
                $offset = max(0, (int) $cursor);
                $data = (array) $client->zRange($key, $offset, $offset + self::COLLECTION_PAGE - 1, ['withscores' => true]);
                $detail['next_cursor'] = $offset + self::COLLECTION_PAGE < $detail['size'] ? (string) ($offset + self::COLLECTION_PAGE) : '0';
                $detail['items'] = collect($data)->map(fn ($score, $member) => [
                    'score' => $score,
                    'value' => $this->presentValue((string) $member, self::MEMBER_PREVIEW_BYTES),
                ])->values()->all();
                break;

            case 'stream':
                $detail['size'] = (int) $client->xLen($key);
                $start = $cursor === '0' ? '-' : '('.$cursor;
                $data = (array) $client->xRange($key, $start, '+', self::COLLECTION_PAGE);
                $ids = array_keys($data);
                $detail['next_cursor'] = count($ids) === self::COLLECTION_PAGE ? (string) end($ids) : '0';
                $detail['items'] = array_map(fn ($id) => [
                    'id' => (string) $id,
                    'value' => $this->presentValue((string) json_encode($data[$id], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), self::MEMBER_PREVIEW_BYTES),
                ], $ids);
                break;
        }

        return $detail;
    }

    /** @param array<int,string> $names */
    public function delete(string $prefix, array $names): int
    {
        $keys = array_map(fn ($name) => $this->fullKey($prefix, (string) $name), $names);
        if ($keys === []) {
            return 0;
        }

        return (int) $this->connection()->client()->del($keys);
    }

    /** `$seconds` null removes the expiry. */
    public function setTtl(string $prefix, string $name, ?int $seconds): bool
    {
        $key = $this->fullKey($prefix, $name);
        $client = $this->connection()->client();
        $this->assertExists($client, $key);

        if ($seconds === null) {
            $client->persist($key);

            return true;
        }

        return (bool) $client->expire($key, $seconds);
    }

    /** Overwrite a string value, keeping its current TTL. */
    public function setString(string $prefix, string $name, string $value): void
    {
        $key = $this->fullKey($prefix, $name);
        $client = $this->connection()->client();
        $this->assertExists($client, $key);
        if ((int) $client->type($key) !== 1) {
            throw new InvalidArgumentException('Only string keys can be edited.');
        }

        $client->rawCommand('SET', $key, $value, 'KEEPTTL');
    }

    /** @return array<string,mixed> server-wide memory info (admin only) */
    public function serverInfo(): array
    {
        $info = (array) $this->connection()->client()->info('memory');
        $server = (array) $this->connection()->client()->info('server');

        return [
            'version' => $server['redis_version'] ?? null,
            'used_memory_human' => $info['used_memory_human'] ?? null,
            'used_memory_peak_human' => $info['used_memory_peak_human'] ?? null,
            'maxmemory_human' => $info['maxmemory_human'] ?? null,
            'maxmemory_policy' => $info['maxmemory_policy'] ?? null,
            'fragmentation_ratio' => $info['mem_fragmentation_ratio'] ?? null,
        ];
    }

    public function fullKey(string $prefix, string $name): string
    {
        if ($prefix === '' || $name === '' || strlen($name) > 1024) {
            throw new InvalidArgumentException('Invalid key name.');
        }

        return $prefix.$name;
    }

    private function connection(): Connection
    {
        return Redis::connection($this->connectionName());
    }

    /**
     * @param array<int,string> $keys
     * @return array<int,array{name:string,type:string,ttl:int}>
     */
    private function describeKeys(string $prefix, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $pipe = $this->connection()->client()->multi(\Redis::PIPELINE);
        foreach ($keys as $key) {
            $pipe->type($key);
            $pipe->ttl($key);
        }
        $replies = array_chunk((array) $pipe->exec(), 2);

        $rows = [];
        foreach ($keys as $i => $key) {
            [$type, $ttl] = $replies[$i] ?? [0, -2];
            $rows[] = [
                'name' => substr($key, strlen($prefix)),
                'type' => self::TYPES[(int) $type] ?? 'unknown',
                'ttl' => (int) $ttl,
            ];
        }

        return $rows;
    }

    /** @return array{0:string,1:array<mixed>} */
    private function scanCollection(\Redis $client, string $command, string $key, string $cursor): array
    {
        $iterator = $cursor === '0' ? null : (int) $cursor;
        $data = [];
        $rounds = 0;
        // Small hashes/sets come back in one round; large ones may return empty batches.
        do {
            $batch = $client->{$command}($key, $iterator, null, self::COLLECTION_PAGE);
            if (is_array($batch)) {
                $data += $command === 'hScan' ? $batch : array_combine($batch, $batch);
            }
        } while ($iterator && count($data) < self::COLLECTION_PAGE && ++$rounds < 20);

        return [(string) ($iterator ?: '0'), $command === 'hScan' ? $data : array_values($data)];
    }

    /**
     * Describe a raw value: detected format, display text, and whether it is binary.
     *
     * @return array{format:string,binary:bool,raw:string,pretty:?string,truncated:bool}
     */
    private function presentValue(string $raw, ?int $limit = null): array
    {
        $truncated = $limit !== null && strlen($raw) > $limit;
        if ($truncated) {
            $raw = substr($raw, 0, $limit);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return ['format' => 'binary', 'binary' => true, 'raw' => $this->hexPreview($raw), 'pretty' => null, 'truncated' => $truncated];
        }

        $format = 'text';
        $pretty = null;
        $trimmed = ltrim($raw);
        if (is_numeric($raw)) {
            $format = 'number';
        } elseif ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true) && json_validate($raw)) {
            $format = 'json';
            $pretty = json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (! $truncated && preg_match('/^(?:[aOsibdNC]:|N;)/', $raw) === 1) {
            // Laravel/WordPress cache values. Never instantiate classes from cache data.
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            if ($decoded !== false || $raw === 'b:0;') {
                $format = 'php-serialized';
                $pretty = print_r($decoded, true);
            }
        }

        return ['format' => $format, 'binary' => false, 'raw' => $raw, 'pretty' => $pretty, 'truncated' => $truncated];
    }

    private function hexPreview(string $raw): string
    {
        $lines = [];
        foreach (str_split(substr($raw, 0, 4096), 16) as $i => $chunk) {
            $hex = str_pad(implode(' ', str_split(bin2hex($chunk), 2)), 47);
            $ascii = preg_replace('/[^\x20-\x7e]/', '.', $chunk);
            $lines[] = sprintf('%08x  %s  %s', $i * 16, $hex, $ascii);
        }

        return implode("\n", $lines);
    }

    private function clip(string $value): string
    {
        $value = strlen($value) > self::MEMBER_PREVIEW_BYTES ? substr($value, 0, self::MEMBER_PREVIEW_BYTES).'…' : $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function memoryUsage(\Redis $client, string $key): ?int
    {
        $bytes = $this->safe(fn () => $client->rawCommand('MEMORY', 'USAGE', $key));

        return is_numeric($bytes) ? (int) $bytes : null;
    }

    private function assertExists(\Redis $client, string $key): void
    {
        if (! $client->exists($key)) {
            throw new InvalidArgumentException('Key not found. It may have expired.');
        }
    }

    private function searchPattern(string $search): string
    {
        $search = trim($search);
        if ($search === '') {
            return '*';
        }
        if (str_contains($search, '*') || str_contains($search, '?')) {
            return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $search);
        }

        return '*'.$this->escapeGlob($search).'*';
    }

    private function escapeGlob(string $value): string
    {
        return addcslashes($value, '\\*?[]');
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
