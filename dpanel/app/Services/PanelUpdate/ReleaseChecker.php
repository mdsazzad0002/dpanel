<?php

namespace App\Services\PanelUpdate;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Whether a newer dPanel release exists (backlog U6). The same source
 * installer.sh uses: the version tags of the GitHub repository, newest by
 * version number, and GitHub's compare of the installed commit with it for
 * the list of changes.
 */
class ReleaseChecker
{
    private const CACHE_KEY = 'panel_update.check';

    private const SHOWN_TAGS = 20;

    private const SHOWN_COMMITS = 50;

    /** @return array{version: string, ref: string, commit: string} */
    public function current(): array
    {
        return [
            'version' => (string) config('app.version', ''),
            'ref' => (string) config('panel_update.release_ref', ''),
            'commit' => (string) config('panel_update.release_commit', ''),
        ];
    }

    /** @return array<string, mixed> */
    public function check(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(max(1, (int) config('panel_update.check_cache_minutes', 30))), fn () => $this->fetch());
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $repo = (string) config('panel_update.repo');
        $current = $this->current();
        $tags = $this->tags($repo);
        $latest = $tags[0] ?? null;
        $base = $current['commit'] !== '' ? $current['commit'] : $current['ref'];
        $compare = $latest !== null && $base !== '' && $base !== $latest ? $this->compare($repo, $base, $latest) : null;

        if ($latest === null || $latest === $current['ref']) {
            $available = false;
        } elseif ($compare !== null) {
            $available = $compare['ahead_by'] > 0;
        } else {
            $available = version_compare(ltrim($latest, 'vV'), ltrim($current['version'], 'vV'), '>');
        }

        return [
            'repo' => $repo,
            'latest' => $latest,
            'tags' => array_slice($tags, 0, self::SHOWN_TAGS),
            'update_available' => $available,
            'ahead_by' => $compare['ahead_by'] ?? null,
            'commits' => $compare['commits'] ?? [],
            'compare_url' => $compare['url'] ?? null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function github(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->withHeaders(['Accept' => 'application/vnd.github+json']);
    }

    private function api(string $path): string
    {
        return rtrim((string) config('panel_update.github_api'), '/').$path;
    }

    /** @return list<string> version tags, newest first */
    private function tags(string $repo): array
    {
        try {
            $response = $this->github()->get($this->api("/repos/{$repo}/tags"), ['per_page' => 100]);
        } catch (ConnectionException) {
            throw new PanelUpdateRefused('GitHub could not be reached to look for releases.', 502);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PanelUpdateRefused('GitHub did not list the releases (HTTP '.$response->status().').', 502);
        }
        $tags = array_values(array_filter(
            array_map(fn ($tag) => is_array($tag) ? (string) ($tag['name'] ?? '') : '', $response->json()),
            fn (string $name) => preg_match('/^v?\d+(\.\d+)*$/', $name) === 1,
        ));
        usort($tags, fn (string $a, string $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $tags;
    }

    /** A ref in a URL path; a branch such as `feature/x` keeps its slash. */
    private static function ref(string $ref): string
    {
        return str_replace('%2F', '/', rawurlencode($ref));
    }

    /**
     * What `head` has that `base` does not, newest first. Null when GitHub
     * cannot compare them, such as for a commit that was never pushed.
     *
     * @return array{ahead_by: int, url: ?string, commits: list<array<string, string>>}|null
     */
    private function compare(string $repo, string $base, string $head): ?array
    {
        try {
            $response = $this->github()->get($this->api("/repos/{$repo}/compare/".self::ref($base).'...'.self::ref($head)));
        } catch (ConnectionException) {
            return null;
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            return null;
        }
        $commits = array_map(fn (array $commit) => [
            'sha' => substr((string) ($commit['sha'] ?? ''), 0, 7),
            'message' => strtok((string) ($commit['commit']['message'] ?? ''), "\n") ?: '',
            'author' => (string) ($commit['commit']['author']['name'] ?? ''),
            'date' => (string) ($commit['commit']['author']['date'] ?? ''),
            'url' => (string) ($commit['html_url'] ?? ''),
        ], array_values(array_filter((array) ($json['commits'] ?? []), 'is_array')));

        return [
            'ahead_by' => (int) ($json['ahead_by'] ?? count($commits)),
            'url' => isset($json['html_url']) ? (string) $json['html_url'] : null,
            'commits' => array_slice(array_reverse($commits), 0, self::SHOWN_COMMITS),
        ];
    }
}
