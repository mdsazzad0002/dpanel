<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\ScriptPathResolver;
use App\Services\Php\PhpService;

class WebsiteWebServerSyncService
{
    public function __construct(
        protected WebsiteService $websiteService,
    ) {
    }

    /**
     * Sync Apache/Nginx vhost files and reload Apache if available.
     *
     * @return array{vhost: array{ran: bool, success: bool, output: string}, apache: array{ran: bool, success: bool, output: string}}
     */
    public function syncWebsite(Website $website, ?string $oldDomain = null): array
    {
        $domain = $this->websiteService->normalizeDomain((string) ($website->domain ?? ''));
        $rootPath = $this->websiteService->normalizeAbsolutePath((string) ($website->root_path ?? ''));
        $documentRoot = $this->websiteService->resolveWebsiteDocumentRoot(
            $rootPath,
            (string) ($website->start_directory ?? '')
        );
        $phpVersion = PhpService::normalizePhpVersion((string) ($website->php_version ?? ''));

        if ($domain === '') {
            throw new \InvalidArgumentException('Website domain is required for vhost sync.');
        }

        if ($rootPath === '') {
            throw new \InvalidArgumentException('Website root path is required for vhost sync.');
        }

        if ($documentRoot === '') {
            throw new \InvalidArgumentException('Website document root is required for vhost sync.');
        }

        $vhostResult = $this->runSyncVhostScript($domain, $documentRoot, $phpVersion, $oldDomain);
        if (! $vhostResult['success']) {
            throw new \RuntimeException($vhostResult['output'] !== '' ? $vhostResult['output'] : 'Website vhost sync failed.');
        }

        $verification = $this->verifySyncedVhostFiles($domain, $documentRoot);
        if (! $verification['valid']) {
            $errors = array_filter(array_merge(
                $verification['apache']['errors'] ?? [],
                $verification['nginx']['errors'] ?? [],
            ));

            throw new \RuntimeException($errors !== [] ? implode(' | ', $errors) : 'Website vhost sync completed, but verification failed.');
        }

        $apacheResult = [
            'ran' => true,
            'success' => true,
            'output' => 'Handled by sync-vhost.sh.',
        ];

        return [
            'vhost' => $vhostResult,
            'apache' => $apacheResult,
            'verification' => $verification,
        ];
    }

    /**
     * Verify the expected Apache/Nginx vhost files were written to the correct paths.
     *
     * @return array{
     *     valid: bool,
     *     apache: array{available: bool, valid: bool, conf_path: string, enabled_path: string, matched_paths: array<int, string>, errors: array<int, string>},
     *     nginx: array{available: bool, valid: bool, conf_path: string, enabled_path: string, matched_paths: array<int, string>, errors: array<int, string>}
     * }
     */
    public function verifySyncedVhostFiles(string $domain, string $documentRoot): array
    {
        $apache = $this->verifyApacheVhost($domain, $documentRoot);
        $nginx = $this->verifyNginxVhost($domain, $documentRoot);

        $availableChecks = array_filter([$apache['available'], $nginx['available']]);
        $valid = $availableChecks === [] ? false : ($apache['valid'] && $nginx['valid']);

        return [
            'valid' => $valid,
            'apache' => $apache,
            'nginx' => $nginx,
        ];
    }

    /**
     * @return array{ran: bool, success: bool, output: string}
     */
    public function runSyncVhostScript(string $domain, string $rootPath, string $phpVersion, ?string $oldDomain = null): array
    {
        try {
            $scriptPath = (string) ScriptPathResolver::resolveScriptPath('sync-vhost');
        } catch (\Throwable) {
            $scriptPath = '';
        }

        if ($scriptPath === '') {
            return [
                'ran' => false,
                'success' => false,
                'output' => 'sync-vhost script not found',
            ];
        }

        $result = ScriptPathResolver::runSystemScriptAsRootWithOutput($scriptPath, array_values(array_filter([
            'sync',
            $this->websiteService->normalizeDomain($domain),
            $this->websiteService->normalizeAbsolutePath($rootPath),
            PhpService::normalizePhpVersion($phpVersion),
            $oldDomain !== null && trim($oldDomain) !== '' ? $this->websiteService->normalizeDomain($oldDomain) : null,
        ], static fn ($value): bool => $value !== null)));

        return [
            'ran' => true,
            'success' => in_array((int) ($result['exit_code'] ?? 1), [0, 3], true),
            'output' => (string) ($result['output'] ?? ''),
        ];
    }

    /**
     * @return array{available: bool, valid: bool, conf_path: string, enabled_path: string, matched_paths: array<int, string>, errors: array<int, string>}
     */
    private function verifyApacheVhost(string $domain, string $rootPath): array
    {
        $confDir = '/etc/apache2/sites-available';
        $enabledDir = '/etc/apache2/sites-enabled';

        if (! is_dir($confDir)) {
            return $this->unavailableVerificationResult();
        }

        $expectedPaths = $this->apacheExpectedVhostPaths($domain, $confDir);
        $expectedEnabledPaths = $this->apacheExpectedVhostPaths($domain, $enabledDir);
        $candidate = $this->pickBestVhostMatch($expectedPaths, $domain, $rootPath, 'apache');

        $errors = [];
        if ($candidate === '') {
            $errors[] = 'Apache vhost file not found at expected path(s): '.implode(', ', $expectedPaths).'.';
        } elseif (! $this->vhostFileLooksValid($candidate, $domain, $rootPath, 'apache')) {
            $errors[] = 'Apache vhost file exists but does not contain the expected domain and root path.';
        }

        $enabledCandidate = $this->pickBestVhostMatch($expectedEnabledPaths, $domain, $rootPath, 'apache');
        if ($enabledCandidate === '') {
            $errors[] = 'Apache vhost symlink not found at expected path(s): '.implode(', ', $expectedEnabledPaths).'.';
        }

        return [
            'available' => true,
            'valid' => $errors === [],
            'conf_path' => $candidate !== '' ? $candidate : $this->expectedVhostPath($confDir, $domain),
            'enabled_path' => $enabledCandidate !== '' ? $enabledCandidate : $this->expectedVhostPath($enabledDir, $domain),
            'matched_paths' => array_values(array_unique(array_merge($expectedPaths, $expectedEnabledPaths))),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{available: bool, valid: bool, conf_path: string, enabled_path: string, matched_paths: array<int, string>, errors: array<int, string>}
     */
    private function verifyNginxVhost(string $domain, string $rootPath): array
    {
        $confDir = '/etc/nginx/sites-available';
        $enabledDir = '/etc/nginx/sites-enabled';

        if (! is_dir($confDir) || ! is_dir($enabledDir)) {
            return $this->unavailableVerificationResult();
        }

        $expectedPaths = $this->nginxExpectedVhostPaths($domain, $confDir);
        $expectedEnabledPaths = $this->nginxExpectedVhostPaths($domain, $enabledDir);
        $candidate = $this->pickBestVhostMatch($expectedPaths, $domain, $rootPath, 'nginx');

        $errors = [];
        if ($candidate === '') {
            $errors[] = 'Nginx vhost file not found at expected path(s): '.implode(', ', $expectedPaths).'.';
        } elseif (! $this->vhostFileLooksValid($candidate, $domain, $rootPath, 'nginx')) {
            $errors[] = 'Nginx vhost file exists but does not contain the expected domain and root path.';
        }

        $enabledCandidate = $this->pickBestVhostMatch($expectedEnabledPaths, $domain, $rootPath, 'nginx');
        if ($enabledCandidate === '') {
            $errors[] = 'Nginx vhost symlink not found at expected path(s): '.implode(', ', $expectedEnabledPaths).'.';
        }

        return [
            'available' => true,
            'valid' => $errors === [],
            'conf_path' => $candidate !== '' ? $candidate : $this->expectedVhostPath($confDir, $domain),
            'enabled_path' => $enabledCandidate !== '' ? $enabledCandidate : $this->expectedVhostPath($enabledDir, $domain),
            'matched_paths' => array_values(array_unique(array_merge($expectedPaths, $expectedEnabledPaths))),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{available: bool, valid: bool, conf_path: string, enabled_path: string, matched_paths: array<int, string>, errors: array<int, string>}
     */
    private function unavailableVerificationResult(): array
    {
        return [
            'available' => false,
            'valid' => true,
            'conf_path' => '',
            'enabled_path' => '',
            'matched_paths' => [],
            'errors' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function findMatchingVhostFiles(string $directory): array
    {
        $pattern = rtrim($directory, '/').'/*.conf';
        $matches = glob($pattern) ?: [];

        return array_values(array_filter($matches, static fn ($path) => is_string($path) && is_file($path)));
    }

    private function expectedVhostPath(string $directory, string $domain): string
    {
        return rtrim($directory, '/').'/'.$domain.'.conf';
    }

    /**
     * @return array<int, string>
     */
    private function apacheExpectedVhostPaths(string $domain, string $directory): array
    {
        return $this->expectedVhostPaths($domain, $directory);
    }

    /**
     * @return array<int, string>
     */
    private function nginxExpectedVhostPaths(string $domain, string $directory): array
    {
        return $this->expectedVhostPaths($domain, $directory);
    }

    /**
     * @return array<int, string>
     */
    private function expectedVhostPaths(string $domain, string $directory): array
    {
        $normalizedDomain = $this->websiteService->normalizeDomain($domain);
        $token = strtolower((string) preg_replace('/[^a-z0-9.-]+/', '-', $normalizedDomain));
        $token = trim($token, '-');
        if ($token === '') {
            $token = 'site';
        }

        $hash = substr(sha1($normalizedDomain), 0, 12);
        if (strlen($token) > 110) {
            $token = substr($token, 0, 110);
        }

        $baseName = $token.'-'.$hash;
        $directory = rtrim($directory, '/');

        return [
            $directory.'/'.$baseName.'.conf',
            $directory.'/'.$normalizedDomain.'.conf',
        ];
    }

    /**
     * @param array<int, string> $matches
     */
    private function pickBestVhostMatch(array $matches, string $domain, string $rootPath, string $type): string
    {
        $firstExisting = '';
        foreach ($matches as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            if ($firstExisting === '') {
                $firstExisting = $path;
            }

            if ($this->vhostFileLooksValid($path, $domain, $rootPath, $type)) {
                return $path;
            }
        }

        return $firstExisting;
    }

    private function vhostFileLooksValid(string $path, string $domain, string $rootPath, string $type): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $content = (string) file_get_contents($path);
        if ($content === '') {
            return false;
        }

        $quotedDomain = preg_quote($domain, '/');
        $normalizedRoot = rtrim($rootPath, '/');
        $quotedRoot = preg_quote($normalizedRoot, '/');

        if ($type === 'apache') {
            return preg_match('/^\s*ServerName\s+'.$quotedDomain.'\s*$/mi', $content) === 1
                && preg_match('/^\s*DocumentRoot\s+'.$quotedRoot.'\/?\s*$/mi', $content) === 1;
        }

        return preg_match('/^\s*server_name\s+.*\b'.$quotedDomain.'\b.*;\s*$/mi', $content) === 1
            && preg_match('/^\s*root\s+'.$quotedRoot.'\/?\s*;\s*$/mi', $content) === 1;
    }
}
