<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\Redis\WebsiteRedisExplorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * phpMyAdmin-style browser for a website's own Redis namespace.
 */
class RedisExplorerController extends Controller
{
    private const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(private readonly WebsiteRedisExplorer $explorer) {}

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);

        return Inertia::render('Websites/RedisExplorer', [
            'website' => $website->only(['id', 'domain']),
            'redis' => [
                'prefix' => $this->explorer->prefixFor($website),
                'host' => (string) config('database.redis.website_cache.host', '127.0.0.1'),
                'port' => (int) config('database.redis.website_cache.port', 6379),
                'database' => (int) config('database.redis.website_cache.database', 1),
            ],
            'isAdmin' => (bool) $request->user()?->hasRole('admin'),
        ]);
    }

    public function keys(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'string', 'regex:/^\d+$/'],
            'count' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $prefix = $this->explorer->prefixFor($this->website($request, $id));

        return $this->attempt(fn () => $this->explorer->scanKeys(
            $prefix,
            (string) ($data['search'] ?? ''),
            (string) ($data['cursor'] ?? '0'),
            (int) ($data['count'] ?? 100),
        ));
    }

    public function stats(Request $request, string $token, string $id): JsonResponse
    {
        $prefix = $this->explorer->prefixFor($this->website($request, $id));
        $isAdmin = (bool) $request->user()?->hasRole('admin');

        return $this->attempt(fn () => [
            'namespace' => $this->explorer->namespaceStats($prefix),
            'server' => $isAdmin ? $this->explorer->serverInfo() : null,
        ]);
    }

    public function show(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'cursor' => ['nullable', 'string', 'max:64', 'regex:/^[\d-]+$/'],
        ]);
        $prefix = $this->explorer->prefixFor($this->website($request, $id));

        return $this->attempt(fn () => $this->explorer->inspect($prefix, $data['name'], (string) ($data['cursor'] ?? '0')));
    }

    public function destroy(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'names' => ['required', 'array', 'min:1', 'max:500'],
            'names.*' => ['required', 'string', 'max:1024'],
        ]);
        $prefix = $this->explorer->prefixFor($this->website($request, $id));

        return $this->attempt(fn () => ['deleted' => $this->explorer->delete($prefix, $data['names'])]);
    }

    public function ttl(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'ttl' => ['nullable', 'integer', 'min:1', 'max:315360000'],
        ]);
        $prefix = $this->explorer->prefixFor($this->website($request, $id));

        return $this->attempt(fn () => ['updated' => $this->explorer->setTtl($prefix, $data['name'], isset($data['ttl']) ? (int) $data['ttl'] : null)]);
    }

    public function update(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'value' => ['present', 'nullable', 'string', 'max:'.WebsiteRedisExplorer::STRING_PREVIEW_BYTES],
        ]);
        $prefix = $this->explorer->prefixFor($this->website($request, $id));

        return $this->attempt(function () use ($prefix, $data) {
            $this->explorer->setString($prefix, $data['name'], (string) ($data['value'] ?? ''));

            return ['updated' => true];
        });
    }

    private function attempt(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback(), 200, [], self::JSON_FLAGS);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Redis is unavailable or the command failed.'], 503);
        }
    }

    private function website(Request $request, string $id): Website
    {
        $website = Website::query()->visibleTo($request->user())->find($id);
        abort_if($website === null, 404);

        return $website;
    }
}
