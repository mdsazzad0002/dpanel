<?php

namespace App\Services;

class PathService
{
    private const HOME_BASE = '/home';
    private const DEFAULT_SITE_DIR = 'public_html';




    public static function websiteBaseDirectory(): string
    {
        $configured = trim((string) config('app.server_base_dir', ''));
        if ($configured !== '') {
            return rtrim(self::normalizeAbsolutePath($configured), '/');
        }
        return self::HOME_BASE;
    }

    public function websiteBaseDirectoryValue(): string
    {
        return self::websiteBaseDirectory();
    }

    /** The home folder of a site owner: <base>/<owner>. */
    public static function siteHome(string $owner): string
    {
        return self::websiteBaseDirectory().'/'.trim($owner, '/');
    }

    /** Whether a path lies below the website base directory. */
    public static function isInsideSiteBase(string $path): bool
    {
        return self::pathStartsWith($path, self::websiteBaseDirectory().'/');
    }




    // Helper functions
    public static function normalizeAbsolutePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    public function normalizeAbsolutePathValue(string $path): string
    {
        return self::normalizeAbsolutePath($path);
    }

    /** True for "/x" and for a Windows path that starts at a drive, such as "C:/x". */
    public static function isAbsolutePath(string $path): bool
    {
        $path = self::normalizeAbsolutePath($path);

        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /** A Windows path. The drive prefix never occurs on a POSIX absolute path. */
    public static function isDrivePath(string $path): bool
    {
        return preg_match('#^[A-Za-z]:(/|$)#', self::normalizeAbsolutePath($path)) === 1;
    }

    /**
     * $path below $base without a leading slash, '' for $base itself, or null
     * when $path is outside $base.
     */
    public static function relativeTo(string $path, string $base): ?string
    {
        $path = rtrim(self::normalizeAbsolutePath($path), '/');
        $base = rtrim(self::normalizeAbsolutePath($base), '/');
        if ($base === '' || ! self::pathStartsWith($path.'/', $base.'/')) {
            return null;
        }

        return ltrim(mb_substr($path, mb_strlen($base)), '/');
    }



    




    public function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public function normalizeRootPath(string $rootPath, string $domain): string
    {
        $layout = $this->deriveWebsiteLayout($domain);
        $normalized = $this->normalizeAbsolutePath($rootPath);

        if ($normalized === '') {
            return $layout['root_path'];
        }

        if (! self::isInsideSiteBase($normalized)) {
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


    /**
     * @return array{site_owner: string, site_dir: string, root_path: string, project_root: string}
     */
    public function deriveWebsiteLayout(string $domain): array
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $siteOwner = $this->normalizeSiteOwnerFromDomain($normalizedDomain);

        return [
            'site_owner' => $siteOwner,
            'site_dir' => self::DEFAULT_SITE_DIR,
            'root_path' => self::siteHome($siteOwner).'/'.self::DEFAULT_SITE_DIR,
            'project_root' => self::siteHome($siteOwner),
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
        if (! self::isInsideSiteBase($rootPath)) {
            return 'site_default';
        }

        $parts = explode('/', (string) self::relativeTo($rootPath, self::websiteBaseDirectory()));

        return $this->normalizeSiteOwner((string) ($parts[0] ?? ''), 'site_default');
    }



    /** Windows paths compare without case, as the file system does; POSIX paths keep it. */
    public static function pathStartsWith(string $path, string $prefix): bool
    {
        $path = self::normalizeAbsolutePath($path);
        $prefix = self::normalizeAbsolutePath($prefix);

        return self::isDrivePath($prefix)
            ? mb_stripos($path, $prefix) === 0
            : str_starts_with($path, $prefix);
    }
}
