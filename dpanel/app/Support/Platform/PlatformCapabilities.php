<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What drust says this system can do (plans/platform-spec.md, section 5).
 *
 * When drust gives no answer every capability counts as open: a closed one
 * returns 404, so failing shut would take the panel down with drust.
 */
class PlatformCapabilities
{
    /** Route name prefix => the capability those routes need. */
    private const ROUTE_CAPABILITIES = [
        'docker.' => 'docker',
        'ftp.' => 'ftp',
        'websites.ftp.' => 'ftp',
        'security.firewall' => 'firewall',
        'websites.terminal.' => 'terminal',
        'websites.redis-cache.' => 'redis',
        'websites.redis-explorer.' => 'redis',
        'reseller.' => 'reseller',
        'security.center.upload-scan' => 'clamav',
        // These run only as OS services, which the panel cannot start without a backend.
        'databases.postgresql' => 'services',
        'databases.pgadmin' => 'services',
        'databases.mongodb' => 'services',
        'ai-gateway.local.' => 'services',
    ];

    private const CACHE_KEY = 'platform.capabilities';

    private const CACHE_SECONDS = 300;

    /** Kept short so a restarted drust is noticed, long enough that a dead one does not slow every page. */
    private const RETRY_SECONDS = 60;

    private const TIMEOUT_SECONDS = 2;

    /** @var array<string, mixed>|null */
    private ?array $report = null;

    public static function forRoute(?string $routeName): ?string
    {
        foreach (self::ROUTE_CAPABILITIES as $prefix => $capability) {
            if (str_starts_with((string) $routeName, $prefix)) {
                return $capability;
            }
        }

        return null;
    }

    public function allows(string $capability): bool
    {
        $value = $this->report()['capabilities'][$capability] ?? true;

        return $value !== false && $value !== 'none';
    }

    public function allowsRoute(?string $routeName): bool
    {
        $capability = self::forRoute($routeName);

        return $capability === null || $this->allows($capability);
    }

    /**
     * Route name prefixes the sidebar must leave out.
     *
     * @return list<string>
     */
    public function closedRoutes(): array
    {
        return array_keys(array_filter(self::ROUTE_CAPABILITIES, fn (string $capability) => ! $this->allows($capability)));
    }

    /**
     * The body of drust's GET /capabilities, or [] when it gave no answer.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $this->report = $cached;
        }

        $report = $this->fetch();
        Cache::put(self::CACHE_KEY, $report, $report === [] ? self::RETRY_SECONDS : self::CACHE_SECONDS);

        return $this->report = $report;
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return [];
        }

        try {
            $request = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS);
            $token = trim((string) config('serverpanel.execution_api_token', ''));
            $response = ($token === '' ? $request : $request->withToken($token))->get(rtrim($baseUrl, '/').'/capabilities');
        } catch (\Throwable) {
            return [];
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json['capabilities'] ?? null) ? $json : [];
    }
}
