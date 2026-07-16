<?php

namespace App\Services\Website;

class WebsiteTemplateCatalogService
{
    protected const MANIFEST_FILE = 'config/website-templates.json';

    /**
     * Resolve the repository root that contains reusable templates/modules.
     */
    public function repositoryRoot(): string
    {
        $configured = trim((string) config('serverpanel.template_repository_dir', ''));
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            dirname(base_path()).'/discript',
            base_path('discript'),
            '/var/www/panel/discript',
            '/root/ServerPanel/discript',
        ]));

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeAbsolutePath((string) $candidate);
            if ($normalized !== '' && is_dir($normalized)) {
                return rtrim($normalized, '/');
            }
        }

        return rtrim($this->normalizeAbsolutePath(dirname(base_path()).'/discript'), '/');
    }

    /**
     * Return the reusable template root.
     */
    public function templateRoot(): string
    {
        return rtrim($this->repositoryRoot(), '/').'/repository/templates';
    }

    /**
     * Return the reusable module root.
     */
    public function moduleRoot(): string
    {
        return rtrim($this->repositoryRoot(), '/').'/repository/modules';
    }

    /**
     * Resolve the manifest file that stores reusable template metadata.
     */
    public function manifestPath(): string
    {
        return base_path(self::MANIFEST_FILE);
    }

    /**
     * Load the manifest as an array.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $path = $this->manifestPath();
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read available PHP versions from reusable template/module files.
     *
     * @return array<int, string>
     */
    public function availablePhpVersions(): array
    {
        $phpModuleDir = rtrim($this->moduleRoot(), '/').'/php';
        $versions = [];
        foreach ((array) ($this->manifest()['php_versions'] ?? []) as $item) {
            $version = trim((string) $item);
            if (preg_match('/^\d+\.\d+$/', $version) === 1) {
                $versions[] = $version;
            }
        }

        if (is_dir($phpModuleDir)) {
            foreach (glob($phpModuleDir.'/*.sh') ?: [] as $path) {
                $basename = basename((string) $path);
                if (preg_match('/^([0-9]+\.[0-9]+)\.sh$/', $basename, $matches) === 1) {
                    $versions[] = $matches[1];
                }
            }
        }

        $versions = array_values(array_unique(array_filter($versions, static fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)));
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return $versions;
    }

    /**
     * Determine the default PHP version for a template family.
     */
    public function defaultPhpVersion(string $template = 'starter'): string
    {
        $template = strtolower(trim($template));
        $manifest = (array) ($this->manifest()['families'] ?? []);
        $family = (array) ($manifest[$template] ?? []);
        $defaults = (array) config('serverpanel.website_php_defaults', []);
        $configured = (string) (
            $family['default_php_version']
            ?? $defaults[$template]
            ?? $defaults['starter']
            ?? 'latest'
        );

        return \App\Http\Controllers\PhpManagementController::normalizePhpVersionSelection(
            $configured,
            $this->availablePhpVersions(),
        );
    }

    /**
     * Safely resolve a path inside the template repository.
     */
    public function templatePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $candidate = rtrim($this->repositoryRoot(), '/').'/'.ltrim($relativePath, '/');
        $root = rtrim($this->repositoryRoot(), '/');
        $normalized = $this->normalizeAbsolutePath($candidate);
        if ($normalized === '' || ! str_starts_with($normalized, $root.'/')) {
            return null;
        }

        return is_file($normalized) ? $normalized : null;
    }

    /**
     * Resolve a template entry from the manifest to an absolute file path.
     */
    public function familyTemplatePath(string $family, string $key): ?string
    {
        $family = strtolower(trim($family));
        $key = strtolower(trim($key));
        if ($family === '' || $key === '') {
            return null;
        }

        $manifest = (array) ($this->manifest()['families'] ?? []);
        $familyData = (array) ($manifest[$family] ?? []);
        $relative = (string) ($familyData[$key] ?? '');
        if ($relative === '') {
            return null;
        }

        return $this->templatePath($relative);
    }

    protected function normalizeAbsolutePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        $segments = [];
        $absolute = str_starts_with($path, '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        return $absolute ? '/'.$normalized : $normalized;
    }
}
