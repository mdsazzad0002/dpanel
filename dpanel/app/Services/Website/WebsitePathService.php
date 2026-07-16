<?php

namespace App\Services\Website;

class WebsitePathService
{
    private const HOME_BASE = '/home';
    private const DEFAULT_SITE_DIR = 'public_html';

    public function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public function normalizeRootPath(string $rootPath, string $domain): string
    {
        $layout = $this->deriveWebsiteLayout($domain);
        $homeBase = $this->websiteBaseDirectory();
        $normalized = $this->normalizeAbsolutePath($rootPath);

        if ($normalized === '') {
            return $layout['root_path'];
        }

        if (! $this->pathStartsWith($normalized, $homeBase.'/')) {
            return $layout['root_path'];
        }

        return rtrim($normalized, '/');
    }

    public function normalizeSiteDirectory(string $siteDir, string $fallback): string
    {
        $siteDir = strtolower(trim($siteDir));
        $siteDir = (string) preg_replace('/[^a-z0-9._-]+/', '_', $siteDir);
        $siteDir = trim($siteDir, '._-');

        if ($siteDir === '' || $siteDir === '.' || $siteDir === '..') {
            return $fallback;
        }

        return substr($siteDir, 0, 64);
    }

    public function normalizeWordPressVersion(string $version): string
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '' || $normalized === 'latest') {
            return 'latest';
        }

        if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $normalized) === 1) {
            return $normalized;
        }

        return 'latest';
    }

    public function websiteBaseDirectory(): string
    {
        $configured = trim((string) config('app.server_base_dir', ''));
        if ($configured !== '') {
            return rtrim($this->normalizeAbsolutePath($configured), '/');
        }

        return self::HOME_BASE;
    }

    /**
     * @return array{site_owner: string, site_dir: string, root_path: string, project_root: string}
     */
    public function deriveWebsiteLayout(string $domain): array
    {
        $homeBase = $this->websiteBaseDirectory();
        $normalizedDomain = $this->normalizeDomain($domain);
        $siteOwner = $this->normalizeSiteOwnerFromDomain($normalizedDomain);

        return [
            'site_owner' => $siteOwner,
            'site_dir' => self::DEFAULT_SITE_DIR,
            'root_path' => $homeBase."/{$siteOwner}/".self::DEFAULT_SITE_DIR,
            'project_root' => $homeBase."/{$siteOwner}",
        ];
    }

    public function deriveProjectRootPath(string $rootPath, string $domain): string
    {
        $normalized = rtrim($this->normalizeAbsolutePath($rootPath), '/');
        if ($normalized !== '') {
            $parent = rtrim(str_replace('\\', '/', dirname($normalized)), '/');
            if ($parent !== '' && $parent !== '.') {
                return $parent;
            }
        }

        $layout = $this->deriveWebsiteLayout($domain);

        return (string) ($layout['project_root'] ?? '');
    }

    public function normalizeSiteOwnerFromDomain(string $domain): string
    {
        $candidate = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $this->normalizeDomain($domain)));
        $candidate = trim($candidate, '_');
        if ($candidate === '' || ctype_digit($candidate[0])) {
            $candidate = 'site_'.$candidate;
        }

        return substr($candidate, 0, 32);
    }

    /**
     * @param array<int, string> $labels
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public function splitDomainParts(array $labels): array
    {
        $count = count($labels);
        if ($count < 2) {
            return [$labels, []];
        }

        $suffixParts = 1;
        if ($count >= 3) {
            $lastTwo = strtolower($labels[$count - 2].'.'.$labels[$count - 1]);
            if (in_array($lastTwo, (array) config('serverpanel.compound_public_suffixes', []), true)) {
                $suffixParts = 2;
            }
        }

        $registrableLength = $suffixParts + 1;
        if ($count < $registrableLength) {
            $registrableLength = 2;
        }

        $registrableLabels = array_slice($labels, -$registrableLength);
        $subLabels = array_slice($labels, 0, -$registrableLength);

        return [$registrableLabels, $subLabels];
    }

    public function buildOwnerSeedFromRegistrable(array $registrableLabels): string
    {
        if (count($registrableLabels) <= 2) {
            return (string) ($registrableLabels[0] ?? 'site');
        }

        return implode('_', $registrableLabels);
    }

    public function normalizeSiteOwner(string $owner, string $fallback): string
    {
        $owner = strtolower(trim($owner));
        if ($owner === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $owner) !== 1) {
            return $fallback;
        }

        return $owner;
    }

    public function extractSiteOwnerFromRootPath(string $rootPath): string
    {
        $homeBase = $this->websiteBaseDirectory();
        $path = $this->normalizeAbsolutePath($rootPath);
        if (! $this->pathStartsWith($path, $homeBase.'/')) {
            return 'site_default';
        }

        $suffix = trim(substr($path, strlen($homeBase.'/')), '/');
        $parts = explode('/', $suffix);

        return $this->normalizeSiteOwner((string) ($parts[0] ?? ''), 'site_default');
    }

    public function normalizeAbsolutePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    public function pathStartsWith(string $path, string $prefix): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $prefix = $this->normalizeAbsolutePath($prefix);

        return str_starts_with($path, $prefix);
    }
}
