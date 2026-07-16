<?php

namespace App\Services\Website;

use App\Models\User;
use App\Services\PathService;

class WebsiteResolverService
{
    public function __construct(
        private readonly WebsiteAccessService $access,
        private readonly PathService $paths,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function findAuthorizedWebsiteOrFail(string $id, ?User $actor = null): array
    {
        return $this->access->findAuthorizedWebsiteOrFail($id, $actor);
    }

    /**
     * @param array<string,mixed> $website
     * @return array<string,mixed>
     */
    public function normalizeWebsiteRecord(array $website): array
    {
        $domain = $this->paths->normalizeDomain((string) ($website['domain'] ?? ''));
        if ($domain === '') {
            return $website;
        }

        $website['domain'] = $domain;
        $website['root_path'] = $this->paths->normalizeRootPath((string) ($website['root_path'] ?? ''), $domain);
        $website['project_root'] = $this->paths->deriveProjectRootPath((string) ($website['root_path'] ?? ''), $domain);
        $website['site_owner'] = $this->paths->extractSiteOwnerFromRootPath((string) $website['root_path']);
        $website['wordpress_version'] = $this->paths->normalizeWordPressVersion((string) ($website['wordpress_version'] ?? 'latest'));

        return $website;
    }

    public function normalizeDomain(string $domain): string
    {
        return $this->paths->normalizeDomain($domain);
    }

    public function normalizeRootPath(string $rootPath, string $domain): string
    {
        return $this->paths->normalizeRootPath($rootPath, $domain);
    }

    public function normalizeSiteDirectory(string $siteDir, string $fallback): string
    {
        return $this->paths->normalizeSiteDirectory($siteDir, $fallback);
    }

    public function normalizeWordPressVersion(string $version): string
    {
        return $this->paths->normalizeWordPressVersion($version);
    }

    public function websiteBaseDirectory(): string
    {
        return $this->paths->websiteBaseDirectory();
    }

    public function deriveProjectRootPath(string $rootPath, string $domain): string
    {
        return $this->paths->deriveProjectRootPath($rootPath, $domain);
    }

    public function deriveWebsiteLayout(string $domain): array
    {
        return $this->paths->deriveWebsiteLayout($domain);
    }

    public function normalizeSiteOwnerFromDomain(string $domain): string
    {
        return $this->paths->normalizeSiteOwnerFromDomain($domain);
    }

    public function splitDomainParts(array $labels): array
    {
        return $this->paths->splitDomainParts($labels);
    }

    public function buildOwnerSeedFromRegistrable(array $registrableLabels): string
    {
        return $this->paths->buildOwnerSeedFromRegistrable($registrableLabels);
    }

    public function normalizeSiteOwner(string $owner, string $fallback): string
    {
        return $this->paths->normalizeSiteOwner($owner, $fallback);
    }

    public function extractSiteOwnerFromRootPath(string $rootPath): string
    {
        return $this->paths->extractSiteOwnerFromRootPath($rootPath);
    }

    public function normalizeAbsolutePath(string $path): string
    {
        return $this->paths->normalizeAbsolutePath($path);
    }

    public function pathStartsWith(string $path, string $prefix): bool
    {
        return $this->paths->pathStartsWith($path, $prefix);
    }

    public function actorCanAccessWebsite(array $website, ?User $actor = null): bool
    {
        return $this->access->actorCanAccessWebsite($website, $actor);
    }
}
