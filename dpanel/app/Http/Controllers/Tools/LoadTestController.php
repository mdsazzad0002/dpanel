<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tools → Load Test. A bounded capacity probe: it sends a capped number of GET
 * requests at a capped concurrency to one of the signed-in account's own
 * domains, and reports latency and status codes. It is deliberately NOT a
 * stress/flood weapon — the ownership gate and the hard caps below mean it can
 * only measure a site you already control, never overwhelm an arbitrary target.
 */
class LoadTestController extends Controller
{
    private const MAX_REQUESTS = 300;

    private const MAX_CONCURRENCY = 20;

    private const PER_REQUEST_TIMEOUT = 8;   // seconds

    private const CONNECT_TIMEOUT = 4;       // seconds

    private const WALL_BUDGET = 25.0;        // seconds; stop launching batches past this

    public function index(Request $request): Response
    {
        return Inertia::render('Tools/LoadTest', [
            'domains' => $this->ownedDomains($request),
            'limits' => [
                'requests' => self::MAX_REQUESTS,
                'concurrency' => self::MAX_CONCURRENCY,
                'timeout' => self::PER_REQUEST_TIMEOUT,
            ],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'requests' => ['required', 'integer', 'min:1', 'max:'.self::MAX_REQUESTS],
            'concurrency' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CONCURRENCY],
            'path' => ['nullable', 'string', 'max:2048'],
        ]);

        $parts = parse_url(trim($validated['url']));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return response()->json(['message' => __('Enter a full http(s) URL.')], 422);
        }

        $owned = $this->ownedDomains($request);
        if (! $this->isOwned($host, $owned)) {
            return response()->json([
                'message' => __('You can only load-test a domain that belongs to your account.'),
                'owned' => $owned,
            ], 403);
        }

        // Rebuild the target from the validated host only, so query/path tricks cannot
        // redirect the probe elsewhere. An optional path is appended, forced relative.
        $path = '/'.ltrim((string) ($validated['path'] ?? ($parts['path'] ?? '/')), '/');
        $target = $scheme.'://'.$host.$path;

        $total = (int) $validated['requests'];
        $concurrency = min((int) $validated['concurrency'], $total);

        $durations = [];
        $statuses = [];
        $failures = 0;
        $sent = 0;
        $startedAt = microtime(true);

        while ($sent < $total && (microtime(true) - $startedAt) < self::WALL_BUDGET) {
            $batch = min($concurrency, $total - $sent);
            $responses = $this->fireBatch($target, $batch);
            $sent += $batch;

            foreach ($responses as $response) {
                if ($response instanceof \Illuminate\Http\Client\Response) {
                    $statuses[$response->status()] = ($statuses[$response->status()] ?? 0) + 1;
                    $time = optional($response->transferStats)->getTransferTime();
                    if ($time !== null) {
                        $durations[] = $time * 1000; // ms
                    }
                } else {
                    $failures++;
                }
            }
        }

        $elapsed = microtime(true) - $startedAt;
        sort($durations);

        ActivityLog::create([
            'id' => (string) Str::uuid(),
            'user_id' => $request->user()?->id,
            'action' => 'tools.load_test',
            'subject_type' => 'domain',
            'subject_id' => Str::limit($host, 60, ''),
            'properties' => ['requests' => $sent, 'concurrency' => $concurrency, 'failures' => $failures],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return response()->json([
            'target' => $target,
            'sent' => $sent,
            'completed' => count($durations),
            'failures' => $failures,
            'statuses' => $statuses,
            'elapsedMs' => (int) round($elapsed * 1000),
            'rps' => $elapsed > 0 ? round($sent / $elapsed, 1) : null,
            'latency' => $durations === [] ? null : [
                'min' => (int) round($durations[0]),
                'max' => (int) round($durations[count($durations) - 1]),
                'avg' => (int) round(array_sum($durations) / count($durations)),
                'p50' => (int) round($this->percentile($durations, 50)),
                'p95' => (int) round($this->percentile($durations, 95)),
            ],
            'capped' => $sent < $total,
        ]);
    }

    /** Fire one concurrent batch of GET requests and return the raw pool results (Response or exception). */
    private function fireBatch(string $target, int $count): array
    {
        return Http::pool(fn ($pool) => array_map(
            fn () => $pool
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::PER_REQUEST_TIMEOUT)
                ->withoutRedirecting()
                ->withOptions(['http_errors' => false])
                ->get($target),
            range(1, $count),
        ));
    }

    /** Domains the signed-in actor is allowed to see, used as the load-test allow-list. */
    private function ownedDomains(Request $request): array
    {
        return Website::query()
            ->visibleTo($request->user())
            ->pluck('domain')
            ->filter()
            ->map(fn ($d) => strtolower((string) $d))
            ->unique()
            ->values()
            ->all();
    }

    /** A host is in scope if, ignoring a leading www., it equals one of the owned domains. */
    private function isOwned(string $host, array $owned): bool
    {
        $strip = static fn (string $h): string => preg_replace('/^www\./', '', $h);
        $needle = $strip($host);

        foreach ($owned as $domain) {
            if ($strip($domain) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function percentile(array $sorted, float $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $rank = ($p / 100) * (count($sorted) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low);
    }
}
