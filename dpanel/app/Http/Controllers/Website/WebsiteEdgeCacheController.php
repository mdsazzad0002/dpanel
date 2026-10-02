<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteEdgeCache;
use App\Services\EdgeCacheClient;
use App\Services\EdgeGatewayReloader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Per-website settings for the edge gateway's response cache. */
class WebsiteEdgeCacheController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);

        return Inertia::render('Websites/EdgeCache', [
            'website' => ['id' => (string) $website->id, 'domain' => (string) $website->domain],
            'isSystem' => $website->scope === 'system',
            'settings' => WebsiteEdgeCache::forWebsite((string) $website->id)->toSettings(),
            'defaults' => [
                'bypass_paths' => implode("\n", WebsiteEdgeCache::DEFAULT_BYPASS_PATHS),
                'bypass_cookies' => implode("\n", WebsiteEdgeCache::DEFAULT_BYPASS_COOKIES),
            ],
        ]);
    }

    public function update(Request $request, string $token, string $id, EdgeGatewayReloader $reloader): JsonResponse
    {
        $website = $this->website($request, $id);
        abort_if($website->scope === 'system', 422, 'The panel itself is never cached.');
        $validated = $request->validate([
            'mode' => ['required', 'in:'.implode(',', WebsiteEdgeCache::MODES)],
            'edge_ttl' => ['required', 'integer', 'min:60', 'max:2592000'],
            'browser_ttl' => ['nullable', 'integer', 'min:0', 'max:31536000'],
            'bypass_paths' => ['nullable', 'string', 'max:5000'],
            'bypass_cookies' => ['nullable', 'string', 'max:5000'],
            'ignore_query_string' => ['required', 'boolean'],
            'serve_stale' => ['required', 'boolean'],
        ]);

        $settings = WebsiteEdgeCache::forWebsite((string) $website->id);
        $settings->fill([
            'mode' => $validated['mode'],
            'edge_ttl' => (int) $validated['edge_ttl'],
            'browser_ttl' => ((int) ($validated['browser_ttl'] ?? 0)) ?: null,
            'bypass_paths' => $this->lines((string) ($validated['bypass_paths'] ?? ''), 'bypass_paths', '/^\/\S*$/', 'Each bypass path must start with / and contain no spaces.'),
            'bypass_cookies' => $this->lines((string) ($validated['bypass_cookies'] ?? ''), 'bypass_cookies', '/^[A-Za-z0-9_.\-]+$/', 'Cookie names may contain letters, digits, _, - and . only.'),
            'ignore_query_string' => (bool) $validated['ignore_query_string'],
            'serve_stale' => (bool) $validated['serve_stale'],
        ])->save();
        // A per-domain reload also drops the site's cached copies.
        $applied = $reloader->reloadDomains([(string) $website->domain]);

        return response()->json([
            'message' => $applied ? 'Cache settings saved and applied.' : 'Cache settings saved. The edge gateway did not confirm the reload; they apply on its next reload.',
            'settings' => $settings->toSettings(),
        ]);
    }

    public function purge(Request $request, string $token, string $id, EdgeCacheClient $cache): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'type' => ['required', 'in:everything,urls,prefixes'],
            'targets' => ['required_unless:type,everything', 'nullable', 'string', 'max:20000'],
        ]);
        $targets = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($validated['targets'] ?? '')) ?: [])));
        if ($validated['type'] !== 'everything' && $targets === []) {
            throw ValidationException::withMessages(['targets' => 'List at least one URL or path.']);
        }

        try {
            $purged = $cache->purge(
                (string) $website->domain,
                $validated['type'] === 'urls' ? $targets : [],
                $validated['type'] === 'prefixes' ? $targets : [],
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Purge failed: '.$exception->getMessage()], 502);
        }

        return response()->json(['message' => "Purged {$purged} cached ".($purged === 1 ? 'copy' : 'copies').'.', 'purged' => $purged]);
    }

    public function developmentMode(Request $request, string $token, string $id, EdgeGatewayReloader $reloader): JsonResponse
    {
        $website = $this->website($request, $id);
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $settings = WebsiteEdgeCache::forWebsite((string) $website->id);
        $settings->development_mode_until = $enabled ? time() + WebsiteEdgeCache::DEVELOPMENT_MODE_SECONDS : null;
        $settings->save();
        $reloader->reloadDomains([(string) $website->domain]);

        return response()->json([
            'message' => $enabled ? 'Development mode is on for 3 hours: every request goes to your server.' : 'Development mode is off.',
            'settings' => $settings->toSettings(),
        ]);
    }

    public function stats(Request $request, string $token, string $id, EdgeCacheClient $cache): JsonResponse
    {
        $website = $this->website($request, $id);
        $stats = $cache->stats((string) $website->domain);

        return $stats === null
            ? response()->json(['message' => 'The edge gateway did not answer.'], 502)
            : response()->json($stats);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }

    /** One entry per line, de-duplicated; every entry must match $pattern. */
    private function lines(string $value, string $field, string $pattern, string $message): ?string
    {
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []))));
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) !== 1) {
                throw ValidationException::withMessages([$field => "{$message} \"{$line}\" is not valid."]);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
