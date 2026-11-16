<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One end-to-end health check of the panel host and its websites: resources
 * and services, backups, SSL, security findings, mail, PHP worker limits and
 * the speed of every live website, each check graded ok / warn / fail.
 *
 * The speed test times three kinds of request per site, so a slow site says
 * where the time goes: a static file (server and network only), a path the
 * app does not have (the framework booting), and the home page (the app's own
 * code). It reuses the other read-only tools rather than repeating their
 * queries.
 */
class RunHealthCheckTool implements McpTool
{
    public const CHECKS = ['resources', 'backups', 'ssl', 'security', 'mail', 'php_workers', 'speed'];

    private const MAX_SITES = 10;

    /** Grades, worst last. */
    private const RANK = ['skipped' => 0, 'ok' => 1, 'warn' => 2, 'fail' => 3];

    public function name(): string
    {
        return 'run_health_check';
    }

    public function title(): string
    {
        return 'Run health check';
    }

    public function description(): string
    {
        return 'End-to-end health check of the panel host and its websites in one call. Grades each check ok / warn / fail: '
            .'resources (memory, disk, load, core services, failed systemd units), backups, SSL expiry, open security findings, '
            .'mail delivery, PHP-FPM sites that ran out of workers in the last 24h, and the speed of every live website. '
            .'The speed test times a static file, a missing path (framework boot) and the home page of each site, so it says whether '
            .'a slow site is the server, the framework or the site\'s own code. Use it for "is everything OK?", "why is the site slow?" '
            .'and after a deploy. Narrow with checks and domains; it can take up to a minute for many sites.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::CHECKS],
                    'description' => 'Only these checks. Default: all.',
                ],
                'domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Websites for the speed test. Default: every live primary website (up to '.self::MAX_SITES.').',
                ],
                'samples' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 3, 'description' => 'Requests per probe; the median is reported.'],
                'via' => [
                    'type' => 'string',
                    'enum' => ['origin', 'public'],
                    'default' => 'origin',
                    'description' => 'origin: requests go straight to this server (no CDN, measures the server and the site). public: through DNS and any CDN, as visitors see it.',
                ],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $started = microtime(true);
        $wanted = $this->wantedChecks($arguments['checks'] ?? null);

        $results = [];
        foreach ($wanted as $check) {
            try {
                $results[$check] = match ($check) {
                    'resources' => $this->resources($token),
                    'backups' => $this->backups($token),
                    'ssl' => $this->ssl($token),
                    'security' => $this->security($token),
                    'mail' => $this->mail($token),
                    'php_workers' => $this->phpWorkers($token),
                    'speed' => $this->speed($arguments, $token),
                };
            } catch (McpToolException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $results[$check] = $this->grade('skipped', [], ['Check could not run: '.Str::limit($e->getMessage(), 300)]);
            }
        }

        $overall = collect($results)->pluck('status')->sortByDesc(fn ($status) => self::RANK[$status] ?? 0)->first() ?? 'ok';
        $summary = collect($results)
            ->filter(fn ($result) => in_array($result['status'], ['fail', 'warn'], true))
            ->sortByDesc(fn ($result) => self::RANK[$result['status']])
            ->flatMap(fn ($result, $check) => array_map(fn ($finding) => $finding + ['check' => $check], $result['findings']))
            ->sortByDesc(fn ($finding) => self::RANK[$finding['level']])
            ->map(fn ($finding) => strtoupper($finding['level'])." [{$finding['check']}] {$finding['message']}")
            ->values()
            ->all();

        return [
            'overall' => $overall === 'skipped' ? 'ok' : $overall,
            'summary' => $summary,
            'checks' => $results,
            'checked_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function wantedChecks(mixed $checks): array
    {
        if (! is_array($checks) || $checks === []) {
            return self::CHECKS;
        }
        $unknown = array_diff($checks, self::CHECKS);
        if ($unknown !== []) {
            throw new McpToolException('Unknown check(s): '.implode(', ', $unknown).'. Use: '.implode(', ', self::CHECKS).'.');
        }

        return array_values(array_intersect(self::CHECKS, $checks));
    }

    /**
     * @param  'ok'|'warn'|'fail'|'skipped'  $status
     * @param  array<int, string>  $notes  Why the check was skipped.
     */
    private function grade(string $status, array $data, array $notes = []): array
    {
        $level = $status === 'skipped' ? 'skipped' : $status;

        return ['status' => $status, 'findings' => array_map(fn ($note) => ['level' => $level, 'message' => $note], array_values($notes)), 'data' => $data];
    }

    /**
     * @param  array<int, array{0: bool, 1: string}>  $failures  [is_fail, message]
     */
    private function gradeFindings(array $failures, array $data): array
    {
        $findings = array_map(fn ($failure) => ['level' => $failure[0] ? 'fail' : 'warn', 'message' => $failure[1]], $failures);
        $status = collect($findings)->pluck('level')->sortByDesc(fn ($level) => self::RANK[$level])->first() ?? 'ok';

        return ['status' => $status, 'findings' => $findings, 'data' => $data];
    }

    private function resources(McpAccessToken $token): array
    {
        $overview = app(PanelOverviewTool::class)->handle([], $token);

        $failures = [];
        foreach ($overview['alerts'] ?? [] as $alert) {
            if (str_contains($alert, 'SSL certificate')) {
                continue; // the ssl check names each one
            }
            $serious = str_starts_with($alert, 'Service ') || str_contains($alert, 'Memory is') || str_contains($alert, 'Disk /');
            $failures[] = [$serious, $alert];
        }

        return $this->gradeFindings($failures, [
            'hostname' => $overview['hostname'] ?? null,
            'load_average' => $overview['load_average'] ?? null,
            'cpu_cores' => $overview['cpu_cores'] ?? null,
            'memory' => $overview['memory'] ?? null,
            'disk_root' => $overview['disk_root'] ?? null,
            'services' => $overview['services'] ?? null,
            'failed_units' => $overview['failed_units'] ?? [],
        ]);
    }

    private function backups(McpAccessToken $token): array
    {
        $result = app(ListBackupsTool::class)->handle(['limit' => 5], $token);
        $summary = $result['summary'] ?? [];
        $hours = $summary['hours_since_last_success'] ?? null;

        $failures = [];
        if (($summary['last_successful_at'] ?? null) === null) {
            $failures[] = [true, 'No successful backup has ever been made. Schedule one: without it nothing can be restored.'];
        } elseif ($hours > 48) {
            $failures[] = [$hours > 24 * 7, "The last successful backup is {$hours} hours old."];
        }
        if (($summary['failed_last_7d'] ?? 0) > 0) {
            $failures[] = [false, "{$summary['failed_last_7d']} backup(s) failed in the last 7 days; list_backups shows why."];
        }

        return $this->gradeFindings($failures, $summary);
    }

    private function ssl(McpAccessToken $token): array
    {
        $certificates = app(ListSslCertificatesTool::class)->handle(['limit' => 200], $token)['certificates'] ?? [];

        $failures = [];
        foreach ($certificates as $cert) {
            $days = $cert['days_left'];
            if ($days !== null && $days < 0) {
                $failures[] = [true, "{$cert['domain']}: certificate expired ".abs($days).' day(s) ago.'];
            } elseif ($days !== null && $days < 14) {
                $renew = $cert['auto_renew'] ? 'auto-renew has not renewed it yet' : 'auto-renew is off';
                $failures[] = [$days < 3, "{$cert['domain']}: certificate expires in {$days} day(s); {$renew}."];
            } elseif (! in_array($cert['status'], ['valid', 'active', 'issued'], true)) {
                $failures[] = [false, "{$cert['domain']}: certificate status is {$cert['status']}."];
            }
        }

        $soonest = collect($certificates)->whereNotNull('days_left')->sortBy('days_left')->first();

        return $this->gradeFindings($failures, [
            'certificates' => count($certificates),
            'soonest_expiry' => $soonest ? ['domain' => $soonest['domain'], 'days_left' => $soonest['days_left']] : null,
        ]);
    }

    private function security(McpAccessToken $token): array
    {
        $result = app(ListSecurityFindingsTool::class)->handle(['limit' => 100], $token);
        $open = collect($result['open_by_severity'] ?? [])->map(fn ($total) => (int) $total);
        $kinds = collect($result['findings'] ?? [])
            ->countBy(fn ($finding) => "{$finding['website']}: {$finding['title']}")
            ->sortDesc()
            ->take(5);

        $failures = [];
        if (($open['critical'] ?? 0) > 0) {
            $failures[] = [true, "{$open['critical']} critical security finding(s) are open."];
        }
        if (($open['high'] ?? 0) > 0) {
            $failures[] = [false, "{$open['high']} high security finding(s) are open. Most common: "
                .$kinds->map(fn ($count, $kind) => "{$kind} ({$count})")->implode('; ').'.'];
        }

        return $this->gradeFindings($failures, [
            'open_by_severity' => $open,
            'most_common' => $kinds,
            'events_24h' => $result['events_24h'] ?? null,
        ]);
    }

    private function mail(McpAccessToken $token): array
    {
        $result = app(GetMailStatusTool::class)->handle(['failures' => 100], $token);
        $queue = $result['queue'] ?? [];
        $rate = $result['delivery_rate_percent'] ?? null;

        // The same cause repeated (one full inbox) reads very differently from many causes.
        $reasons = collect($result['recent_failures'] ?? [])
            ->countBy(fn ($failure) => ($failure['diagnosis']['label'] ?? $failure['status'] ?? 'failure').': '
                .Str::limit(preg_replace('/\s+/', ' ', preg_replace('/\[[^\]]*\]/', '', (string) ($failure['reason'] ?? ''))), 110))
            ->sortDesc()
            ->take(5);

        $failures = [];
        if (($queue['count'] ?? 0) > 100) {
            $failures[] = [($queue['count'] ?? 0) > 1000, "{$queue['count']} message(s) wait in the mail queue."];
        }
        if ($rate !== null && $rate < 80) {
            $failures[] = [$rate < 50, "Only {$rate}% of recent mail was delivered. Top reasons: "
                .($reasons->isEmpty() ? 'none recorded' : $reasons->map(fn ($count, $reason) => "{$reason} ({$count})")->implode('; ')).'.'];
        }
        foreach ($result['domains'] ?? [] as $domain) {
            $missing = array_keys(array_filter(['DKIM' => ! $domain['dkim'], 'SPF' => ! $domain['spf'], 'DMARC' => ! $domain['dmarc']]));
            if ($missing !== []) {
                $failures[] = [false, "{$domain['domain']}: ".implode(', ', $missing).' not enabled; mail is more likely to land in spam.'];
            }
        }

        return $this->gradeFindings($failures, [
            'health_score' => $result['health_score'] ?? null,
            'delivery_rate_percent' => $rate,
            'stats' => $result['stats'] ?? null,
            'queue_count' => $queue['count'] ?? 0,
            'top_failure_reasons' => $reasons,
        ]);
    }

    private function phpWorkers(McpAccessToken $token): array
    {
        if (! $token->user?->hasRole('admin')) {
            return $this->grade('skipped', [], ['Only an admin token can read PHP worker usage.']);
        }

        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return $this->grade('skipped', [], ['drust API is not configured.']);
        }
        $http = Http::acceptJson()->timeout(20);
        $apiToken = trim((string) config('dpanel.execution_api_token', ''));
        if ($apiToken !== '') {
            $http = $http->withToken($apiToken);
        }
        $response = $http->get(rtrim($baseUrl, '/').'/api/v1/php/workers', ['observer_pid' => getmypid()]);
        if ($response->status() === 404) {
            return $this->grade('skipped', [], ['This drust does not report PHP workers yet; update drust.']);
        }
        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            return $this->grade('skipped', [], ['drust did not report PHP workers: '.Str::limit((string) $response->json('message', $response->body()), 200)]);
        }

        $pools = collect($data['pools'] ?? []);
        $starved = $pools->filter(fn ($pool) => ($pool['limit_hits_24h'] ?? 0) > 0)->sortByDesc('limit_hits_24h');

        $failures = $starved->take(8)->map(fn ($pool) => [
            ($pool['limit_hits_24h'] ?? 0) >= 50,
            "{$pool['owner']} (PHP {$pool['version']}) ran out of workers {$pool['limit_hits_24h']} time(s) in 24h at a limit of {$pool['max_children']}; visitors waited. Raise it on the PHP Workers page.",
        ])->values()->all();
        if ($starved->count() > 8) {
            $failures[] = [false, ($starved->count() - 8).' more site(s) also ran out of workers.'];
        }

        return $this->gradeFindings($failures, [
            'pools' => $pools->count(),
            'busy_now' => $pools->sum('active'),
            'running_now' => $pools->sum('running'),
            'sites_out_of_workers_24h' => $starved->count(),
            'memory_available_mb' => intdiv((int) ($data['memory_available_kb'] ?? 0), 1024),
            'average_worker_mb' => intdiv((int) ($data['average_worker_kb'] ?? 0), 1024),
        ]);
    }

    private function speed(array $arguments, McpAccessToken $token): array
    {
        $samples = max(1, min(5, (int) ($arguments['samples'] ?? 3)));
        $viaOrigin = ($arguments['via'] ?? 'origin') !== 'public';
        $sites = $this->sitesToTest($arguments['domains'] ?? null, $token);

        if ($sites->isEmpty()) {
            return $this->grade('skipped', [], ['No live website to test.']);
        }

        $reports = [];
        $failures = [];
        foreach ($sites as $site) {
            $report = $this->measureSite($site, $samples, $viaOrigin);
            $reports[] = $report;
            foreach ($report['problems'] as [$isFail, $problem]) {
                $failures[] = [$isFail, "{$site->domain}: {$problem}"];
            }
        }

        return $this->gradeFindings($failures, [
            'via' => $viaOrigin ? 'origin (this server, no CDN)' : 'public (DNS and CDN)',
            'samples' => $samples,
            'sites' => collect($reports)
                ->map(function ($report) {
                    unset($report['problems']);

                    return $report;
                })
                ->sortByDesc(fn ($report) => $report['home_page_ms'] ?? PHP_INT_MAX)
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Website>
     */
    private function sitesToTest(mixed $domains, McpAccessToken $token)
    {
        $query = Website::query()->visibleTo($token->user)->orderBy('domain');

        if (is_array($domains) && $domains !== []) {
            $names = collect($domains)->map(fn ($domain) => mb_strtolower(trim((string) $domain)))->filter()->unique();
            $sites = $query->whereIn('domain', $names->all())->get();
            $missing = $names->diff($sites->pluck('domain'));
            if ($missing->isNotEmpty()) {
                throw new McpToolException('No website found for: '.$missing->implode(', ').'. Call list_websites to search.');
            }

            return $sites->take(self::MAX_SITES);
        }

        return $query->where('type', 'primary')->get()
            ->filter(fn (Website $site) => in_array($site->status, ['live', 'active'], true)
                && filter_var($site->domain, FILTER_VALIDATE_IP) === false)
            ->take(self::MAX_SITES)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function measureSite(Website $site, int $samples, bool $viaOrigin): array
    {
        $scheme = ($site->ssl_mode && $site->ssl_mode !== 'none') ? 'https' : 'http';
        $base = "{$scheme}://{$site->domain}";
        $problems = [];

        $page = $this->probe($base.'/', $samples, $viaOrigin, $site->domain);
        if ($page['error'] !== null && $scheme === 'https') {
            // A certificate not yet issued, or one the panel has not recorded.
            $base = "http://{$site->domain}";
            $page = $this->probe($base.'/', $samples, $viaOrigin, $site->domain);
        }
        if ($page['error'] !== null) {
            return [
                'domain' => $site->domain,
                'error' => $page['error'],
                'problems' => [[true, 'The home page could not be fetched: '.$page['error']]],
            ];
        }

        $static = $this->probe($base.$this->staticPath($page['body'], $site->domain), $samples, $viaOrigin, $site->domain);
        $isPhp = ($site->runtime ?: 'php') === 'php';
        $framework = $isPhp
            ? $this->probe($base.'/__dpanel-health-'.Str::lower(Str::random(10)), $samples, $viaOrigin, $site->domain)
            : null;

        $staticMs = $static['error'] === null ? $static['ttfb_ms'] : null;
        $frameworkMs = $framework && $framework['error'] === null ? $framework['ttfb_ms'] : null;
        $pageMs = $page['ttfb_ms'];
        $appMs = $frameworkMs !== null ? max(0, $pageMs - $frameworkMs) : null;
        $cacheable = ! preg_match('/no-cache|no-store|private/i', $page['cache_control'] ?? '') && ! $page['sets_cookie'];

        if ($page['status'] >= 500) {
            $problems[] = [true, "the home page answers HTTP {$page['status']}."];
        }
        if ($staticMs !== null && $staticMs > 800) {
            $problems[] = [$staticMs > 2000, "even a static file takes {$staticMs} ms: the server or the network is slow, not the site's code."];
        } elseif ($frameworkMs !== null && $staticMs !== null && $frameworkMs - $staticMs > 400) {
            $problems[] = [false, "the framework alone takes {$frameworkMs} ms against {$staticMs} ms for a static file: check OPcache and the config/route caches."];
        }
        if ($appMs !== null && $appMs > 500) {
            $problems[] = [$appMs > 1500, "the home page's own code takes about {$appMs} ms on top of {$frameworkMs} ms for the framework "
                .'(database queries, API calls, rendering): profile that page'.($cacheable ? '.' : ', or cache it for guests.')];
        } elseif ($appMs === null && $pageMs > 1500) {
            $problems[] = [$pageMs > 3000, "the home page takes {$pageMs} ms to start answering."];
        }
        if (! $cacheable && $pageMs > 500) {
            $problems[] = [false, 'the home page cannot be cached (Cache-Control: '.($page['cache_control'] ?: 'none')
                .($page['sets_cookie'] ? ', and it sets a cookie for every visitor' : '').'), so every visit runs the app.'];
        }

        return [
            'domain' => $site->domain,
            'url' => $base.'/',
            'status' => $page['status'],
            'home_page_ms' => $pageMs,
            'framework_ms' => $frameworkMs,
            'static_file_ms' => $staticMs,
            'app_code_ms' => $appMs,
            'size_kb' => $page['size_kb'],
            'compressed' => $page['encoding'],
            'cacheable_for_guests' => $cacheable,
            'verdict' => $this->verdict($staticMs, $frameworkMs, $appMs, $pageMs),
            'problems' => $problems,
        ];
    }

    private function verdict(?int $staticMs, ?int $frameworkMs, ?int $appMs, int $pageMs): string
    {
        if ($staticMs !== null && $staticMs > 800) {
            return 'slow: server or network';
        }
        if ($frameworkMs !== null && $staticMs !== null && $frameworkMs - $staticMs > 400) {
            return 'slow: framework boot';
        }
        if ($appMs !== null && $appMs > 500) {
            return 'slow: the site\'s own code';
        }

        return $pageMs > 1500 ? 'slow' : 'fast';
    }

    /** A CSS or JS file the home page links on its own host, or robots.txt. */
    private function staticPath(string $html, string $domain): string
    {
        if (preg_match_all('/(?:src|href)=["\']([^"\'#?]+\.(?:css|js))(?:\?[^"\']*)?["\']/i', $html, $matches)) {
            foreach ($matches[1] as $asset) {
                $host = parse_url($asset, PHP_URL_HOST);
                if ($host === null && str_starts_with($asset, '/') && ! str_starts_with($asset, '//')) {
                    return $asset;
                }
                if ($host !== null && strcasecmp($host, $domain) === 0) {
                    return (string) parse_url($asset, PHP_URL_PATH);
                }
            }
        }

        return '/robots.txt';
    }

    /**
     * Median time to first byte over $samples requests. Redirects are not
     * followed: each hop would hide the time of the one before.
     *
     * @return array{error: string|null, status: int, ttfb_ms: int, size_kb: float, cache_control: string|null, sets_cookie: bool, encoding: string|null, body: string}
     */
    private function probe(string $url, int $samples, bool $viaOrigin, string $domain): array
    {
        // With an Accept-Encoding header, decode_content makes curl ask for gzip
        // and undo it, so the size and the HTML (for the static file) are real.
        $options = ['allow_redirects' => false, 'decode_content' => 'gzip'];
        if ($viaOrigin) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$domain}:443:127.0.0.1", "{$domain}:80:127.0.0.1"]];
        }

        $times = [];
        $last = null;
        $error = null;
        for ($i = 0; $i < $samples; $i++) {
            try {
                /** @var Response $response */
                $response = Http::withOptions($options)
                    ->withHeaders(['User-Agent' => 'dPanel-HealthCheck/1.0', 'Accept-Encoding' => 'gzip'])
                    ->timeout(20)
                    ->get($url);
                $stats = $response->handlerStats();
                $times[] = (int) round(((float) ($stats['starttransfer_time'] ?? $stats['total_time'] ?? 0)) * 1000);
                $last = $response;
            } catch (\Throwable $e) {
                $error = Str::limit($e->getMessage(), 200);
                break;
            }
        }

        if ($last === null) {
            return ['error' => $error ?? 'no response', 'status' => 0, 'ttfb_ms' => 0, 'size_kb' => 0.0, 'cache_control' => null, 'sets_cookie' => false, 'encoding' => null, 'body' => ''];
        }
        sort($times);

        return [
            'error' => null,
            'status' => $last->status(),
            'ttfb_ms' => $times[intdiv(count($times), 2)],
            'size_kb' => round(strlen($last->body()) / 1024, 1),
            'cache_control' => $last->header('Cache-Control') ?: null,
            'sets_cookie' => $last->header('Set-Cookie') !== '',
            'encoding' => ($last->header('X-Encoded-Content-Encoding') ?: $last->header('Content-Encoding')) ?: null,
            'body' => Str::limit($last->body(), 500000, ''),
        ];
    }
}
