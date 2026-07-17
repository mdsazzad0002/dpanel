<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\ScriptPathResolver;

class WebsiteService
{
    public static function existWebsite(string $name)
    {
        $website = Website::where('domain', $name)->exists();
        if ($website) {
            return true;
        }

        return false;
    }

    /**
     * Create or refresh a lightweight demo site page inside the website root.
     *
     * @return array{index_html:?string, extra_note:?string}
     */
    public function createDemoSitePage(string $rootPath, string $domain, ?string $phpVersion = null, ?string $startDirectory = null): array
    {
        $rootPath = $this->normalizeAbsolutePath($rootPath);
        if ($rootPath === '') {
            throw new \InvalidArgumentException('Website root path is required.');
        }

        $normalizedDomain = $this->normalizeDomain($domain);
        $selectedPhpVersion = trim((string) $phpVersion);
        if ($selectedPhpVersion === '' || preg_match('/^\d+\.\d+$/', $selectedPhpVersion) !== 1) {
            $selectedPhpVersion = 'auto';
        }
        $normalizedStartDirectory = $this->normalizeRelativeDirectory((string) $startDirectory, '');

        $scriptPath = $this->createDemoScriptPath();
        $command = implode(' ', array_map('escapeshellarg', [
            $scriptPath,
            $rootPath,
            $normalizedDomain,
            $selectedPhpVersion,
            $normalizedStartDirectory,
        ]));

        $output = [];
        $exitCode = 1;
        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $message = trim(implode("\n", $output));
            throw new \RuntimeException($message !== '' ? $message : 'Failed to create demo site files.');
        }

        return [
            'index_html' => $this->joinPaths($rootPath, $normalizedStartDirectory).'/index.html',
            'extra_note' => $this->joinPaths($rootPath, $normalizedStartDirectory).'/extra/first-site-note.txt',
        ];
    }

    public function normalizeAbsolutePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    public function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public function normalizeRelativeDirectory(string $path, string $fallback): string
    {
        $path = strtolower(trim(str_replace('\\', '/', $path)));
        $path = trim($path, '/');
        $path = (string) preg_replace('/[^a-z0-9._-]+/', '_', $path);
        $path = trim($path, '._-');

        if ($path === '' || $path === '.' || $path === '..') {
            return $fallback;
        }

        return substr($path, 0, 64);
    }

    public function joinPaths(string ...$segments): string
    {
        if ($segments === []) {
            return '';
        }

        $isAbsolute = str_starts_with(str_replace('\\', '/', $segments[0]), '/');
        $segments = array_values(array_filter(array_map(
            fn (string $segment): string => trim(str_replace('\\', '/', $segment), '/'),
            $segments,
        ), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return '';
        }

        return ($isAbsolute ? '/' : '').implode('/', $segments);
    }

    public function resolvePreviewRootPath(Website $website): string
    {
        $rootPath = $this->normalizeAbsolutePath((string) ($website->root_path ?? ''));
        if ($rootPath === '') {
            return '';
        }

        $startDirectory = $this->normalizeRelativeDirectory((string) ($website->start_directory ?? ''), '');
        if ($startDirectory === '') {
            return $rootPath;
        }

        return $this->joinPaths($rootPath, $startDirectory);
    }

    public function resolveWebsiteDocumentRoot(string $rootPath, ?string $startDirectory = null): string
    {
        $normalizedRootPath = $this->normalizeAbsolutePath($rootPath);
        if ($normalizedRootPath === '') {
            return '';
        }

        $normalizedStartDirectory = $this->normalizeRelativeDirectory((string) $startDirectory, '');
        if ($normalizedStartDirectory === '') {
            return $normalizedRootPath;
        }

        return $this->joinPaths($normalizedRootPath, $normalizedStartDirectory);
    }

    public function resolvePreviewFile(string $path): string|false
    {
        $normalized = $this->normalizeAbsolutePath($path);
        if ($normalized === '') {
            return false;
        }

        $real = realpath($normalized);
        if ($real !== false && is_file($real)) {
            return $real;
        }

        if (is_dir($normalized)) {
            foreach (['index.html', 'index.php'] as $fileName) {
                $candidate = realpath($normalized.DIRECTORY_SEPARATOR.$fileName);
                if ($candidate !== false && is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        if (! is_dir($normalized)) {
            $directory = dirname($normalized);
            foreach (['index.html', 'index.php'] as $fileName) {
                $candidate = realpath($directory.DIRECTORY_SEPARATOR.$fileName);
                if ($candidate !== false && is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return false;
    }

    public function pathIsInside(string $file, string $directory): bool
    {
        $file = $this->normalizeAbsolutePath($file);
        $directory = rtrim($this->normalizeAbsolutePath($directory), '/');

        if ($file === '' || $directory === '') {
            return false;
        }

        return $file === $directory || str_starts_with($file, $directory.'/');
    }

    private function createDemoScriptPath(): string
    {
        $scriptPath = ScriptPathResolver::resolveRepositoryRoot().DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'create-demo-site.sh';
        if (! is_file($scriptPath)) {
            throw new \RuntimeException('Demo site script is missing: '.$scriptPath);
        }

        return $scriptPath;
    }
}
