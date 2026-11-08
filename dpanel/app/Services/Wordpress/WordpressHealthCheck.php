<?php

namespace App\Services\Wordpress;

use App\Models\Website;
use App\Services\Platform\SiteLogClient;
use Illuminate\Support\Facades\Http;

/**
 * Whether a WordPress site still works (backlog W5): its home page and
 * login page answer without a server error or WordPress's "critical error"
 * page, and its PHP log has no new fatal error from its own files.
 */
class WordpressHealthCheck
{
    private const BROKEN_PAGE = ['There has been a critical error', 'Fatal error</b>', 'Parse error</b>', 'Error establishing a database connection'];

    public function __construct(private readonly SiteLogClient $logs) {}

    /** A mark in the PHP log to compare against after a change. */
    public function logMark(Website $website): array
    {
        try {
            return $this->logs->tail('php', (string) $website->domain, (string) $website->site_owner, 200)['lines'];
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * @param  list<string>  $before  from {@see logMark()}
     * @return list<string> what is wrong; empty when healthy
     */
    public function problems(Website $website, string $home, array $before = []): array
    {
        $problems = [];
        foreach ([rtrim($home, '/').'/', rtrim($home, '/').'/wp-login.php'] as $url) {
            $problem = $this->pageProblem($url);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        // The account's log is shared with its other sites, so only errors
        // in this site's own files count.
        $root = rtrim((string) $website->root_path, '/');
        $after = $this->logMark($website);
        foreach (self::newLines($before, $after) as $line) {
            if (preg_match('/PHP (Fatal|Parse) error/i', $line) === 1 && ($root === '' || str_contains($line, $root.'/'))) {
                $problems[] = 'PHP: '.mb_strimwidth(trim($line), 0, 300, '…');
            }
        }

        return $problems;
    }

    private function pageProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443));
        try {
            // Asked of this server's own gateway, so a staging name that is
            // not in public DNS still works; a staging certificate may be self-signed.
            $response = Http::withOptions([
                'verify' => false,
                'allow_redirects' => ['max' => 3],
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:127.0.0.1"]],
            ])->timeout(30)->withHeaders(['User-Agent' => 'dPanel-SafeUpdate/1'])->get($url);
        } catch (\Throwable $e) {
            return "{$url} did not answer: ".$e->getMessage();
        }
        if ($response->status() >= 500) {
            return "{$url} answered {$response->status()}.";
        }
        $body = $response->body();
        foreach (self::BROKEN_PAGE as $marker) {
            if (str_contains($body, $marker)) {
                return "{$url} shows \"{$marker}\".";
            }
        }

        return null;
    }

    /**
     * Lines of `$after` that came after the end of `$before`.
     *
     * @return list<string>
     */
    public static function newLines(array $before, array $after): array
    {
        if ($before === []) {
            return $after;
        }
        $last = end($before);
        $position = array_search($last, array_reverse($after, true), true);
        if ($position === false) {
            // Rotated, or more than the tail was written: all of it is new.
            return $after;
        }

        return array_values(array_slice($after, $position + 1));
    }
}
