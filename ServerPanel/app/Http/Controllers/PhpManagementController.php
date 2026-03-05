<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PhpManagementController extends Controller
{
    private const STATE_SETTING_KEY = 'state';
    private const SETTINGS_TABLE = 'php_management_settings';
    private const LEGACY_STORAGE_FILE = 'php-management.json';
    /**
     * @var array<int, string>
     */
    private const CANDIDATE_VERSIONS = ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];

    /**
     * @var array<int, string>
     */
    private const DEFAULT_VERSIONS = ['8.3', '8.2', '8.1'];

    /**
     * @var array<int, string>
     */
    private const DEFAULT_EXTENSIONS = [
        'bcmath',
        'bz2',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'gd',
        'intl',
        'mbstring',
        'mysqli',
        'openssl',
        'pdo_mysql',
        'soap',
        'tokenizer',
        'xml',
        'zip',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_CONFIG = [
        'memory_limit' => '256M',
        'upload_max_filesize' => '128M',
        'post_max_size' => '128M',
        'max_execution_time' => '300',
        'max_input_vars' => '3000',
        'display_errors' => 'Off',
        'log_errors' => 'On',
        'allow_url_fopen' => 'On',
    ];

    public function manager(Request $request): Response
    {
        $state = $this->readState();
        $selectedVersion = (string) $request->query('version', $state['default_version']);

        return Inertia::render('PhpManager', $this->buildManagerPayload($state, $selectedVersion));
    }

    public function versions(): Response
    {
        return $this->manager(request());
    }

    public function checkInstalledVersions(): JsonResponse
    {
        return response()->json([
            'installed_versions' => $this->detectServerPhpVersions(),
        ]);
    }

    public function updateVersions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'installed_versions' => ['required', 'array', 'min:1'],
            'installed_versions.*' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'current_version' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ]);

        $versions = collect($validated['installed_versions'])
            ->map(fn ($version) => trim((string) $version))
            ->filter()
            ->unique()
            ->sort(fn ($a, $b) => version_compare($b, $a))
            ->values()
            ->all();

        $serverVersions = $this->detectServerPhpVersions();
        if (count($serverVersions) > 0) {
            $missing = array_values(array_diff($versions, $serverVersions));
            if (count($missing) > 0) {
                return redirect()->route('php.manager')->with(
                    'error',
                    'These versions are not installed on server: '.implode(', ', $missing),
                );
            }
        }

        $currentVersion = (string) $validated['current_version'];
        if (! in_array($currentVersion, $versions, true)) {
            $currentVersion = $versions[0];
        }

        $state = $this->readState();
        $state['versions'] = $versions;
        $state['default_version'] = $currentVersion;
        $state['extensions'] = $this->normalizeExtensions($state['extensions'] ?? [], $versions);
        $state['config'] = $this->normalizeConfig($state['config'] ?? [], $versions);

        $this->writeState($state);

        return redirect()->route('php.manager', ['version' => $currentVersion])->with('success', 'PHP versions updated successfully.');
    }

    public function refreshVersionsFromServer(Request $request): RedirectResponse|JsonResponse
    {
        $detectedVersions = $this->detectServerPhpVersions();
        if (count($detectedVersions) === 0) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No PHP versions detected from server.',
                ], 422);
            }

            return redirect()->route('php.manager')->with('error', 'No PHP versions detected from server.');
        }

        $state = $this->readState();
        $currentVersion = $this->detectCurrentPhpVersion();
        if ($currentVersion === '' || ! in_array($currentVersion, $detectedVersions, true)) {
            $currentVersion = $detectedVersions[0];
        }

        $state['versions'] = $detectedVersions;
        $state['default_version'] = $currentVersion;
        $state['extensions'] = $this->syncAllExtensionsFromServer($state['extensions'] ?? [], $detectedVersions);
        $state['config'] = $this->syncConfigFromServer($state['config'] ?? [], $detectedVersions);

        $this->writeState($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'PHP settings synced from server successfully.',
                'data' => $this->buildManagerPayload($state, $currentVersion),
            ]);
        }

        return redirect()->route('php.manager', ['version' => $currentVersion])->with('success', 'PHP settings synced from server successfully.');
    }

    public function extensions(Request $request): Response
    {
        $state = $this->readState();
        $versions = $state['versions'];
        $selectedVersion = (string) $request->query('version', $state['default_version']);
        if (! in_array($selectedVersion, $versions, true)) {
            $selectedVersion = $state['default_version'];
        }

        $availableExtensions = $this->detectExtensionsForVersion($selectedVersion);
        if (count($availableExtensions) === 0) {
            $availableExtensions = self::DEFAULT_EXTENSIONS;
        }

        $extensionStates = $state['extensions'][$selectedVersion] ?? [];
        foreach ($availableExtensions as $extension) {
            if (! array_key_exists($extension, $extensionStates)) {
                $extensionStates[$extension] = true;
            }
        }

        return Inertia::render('PhpExtensions', [
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'availableExtensions' => $availableExtensions,
            'extensionStates' => $extensionStates,
        ]);
    }

    public function updateExtensions(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->readState();
        $versions = $state['versions'];

        $validated = $request->validate([
            'version' => ['required', 'string'],
            'extensions' => ['nullable', 'array'],
            'extensions.*' => ['string', 'max:100'],
        ]);

        $version = (string) $validated['version'];
        if (! in_array($version, $versions, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected PHP version is invalid.',
                ], 422);
            }

            return redirect()->route('php.manager')->with('error', 'Selected PHP version is invalid.');
        }

        $availableExtensions = $this->detectExtensionsForVersion($version);
        if (count($availableExtensions) === 0) {
            $availableExtensions = self::DEFAULT_EXTENSIONS;
        }

        $enabledExtensions = collect($validated['extensions'] ?? [])
            ->map(fn ($item) => strtolower(trim((string) $item)))
            ->filter(fn ($item) => in_array($item, $availableExtensions, true))
            ->unique()
            ->values()
            ->all();

        $state['extensions'][$version] = collect($availableExtensions)
            ->mapWithKeys(fn ($extension) => [$extension => in_array($extension, $enabledExtensions, true)])
            ->all();

        $this->writeState($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "PHP extensions updated for {$version}.",
            ]);
        }

        return redirect()->route('php.manager', ['version' => $version])->with('success', "PHP extensions updated for {$version}.");
    }

    public function syncExtensionsFromServer(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->readState();
        $versions = $state['versions'];
        $version = (string) $request->input('version', $state['default_version']);

        if (! in_array($version, $versions, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected PHP version is invalid.',
                ], 422);
            }

            return redirect()->route('php.manager')->with('error', 'Selected PHP version is invalid.');
        }

        $availableExtensions = $this->detectExtensionsForVersion($version);
        if (count($availableExtensions) === 0) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => "No extensions detected from server for PHP {$version}.",
                ], 422);
            }

            return redirect()->route('php.manager', ['version' => $version])->with('error', "No extensions detected from server for PHP {$version}.");
        }

        $state['extensions'][$version] = collect($availableExtensions)
            ->mapWithKeys(fn ($extension) => [(string) $extension => true])
            ->all();
        $this->writeState($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Extensions synced from server for PHP {$version}.",
                'data' => [
                    'availableExtensions' => $availableExtensions,
                    'extensionStates' => $state['extensions'][$version],
                    'version' => $version,
                ],
            ]);
        }

        return redirect()->route('php.manager', ['version' => $version])->with('success', "Extensions synced from server for PHP {$version}.");
    }

    public function config(Request $request): Response
    {
        $state = $this->readState();
        $versions = $state['versions'];
        $selectedVersion = (string) $request->query('version', $state['default_version']);
        if (! in_array($selectedVersion, $versions, true)) {
            $selectedVersion = $state['default_version'];
        }

        return Inertia::render('PhpConfig', [
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'configValues' => $state['config'][$selectedVersion] ?? self::DEFAULT_CONFIG,
        ]);
    }

    public function updateConfig(Request $request): RedirectResponse
    {
        $state = $this->readState();
        $versions = $state['versions'];

        $validated = $request->validate([
            'version' => ['required', 'string'],
            'memory_limit' => ['required', 'string', 'max:20'],
            'upload_max_filesize' => ['required', 'string', 'max:20'],
            'post_max_size' => ['required', 'string', 'max:20'],
            'max_execution_time' => ['required', 'integer', 'min:1', 'max:3600'],
            'max_input_vars' => ['required', 'integer', 'min:100', 'max:50000'],
            'display_errors' => ['required', 'in:On,Off'],
            'log_errors' => ['required', 'in:On,Off'],
            'allow_url_fopen' => ['required', 'in:On,Off'],
        ]);

        $version = (string) $validated['version'];
        if (! in_array($version, $versions, true)) {
            return redirect()->route('php.manager')->with('error', 'Selected PHP version is invalid.');
        }

        $state['config'][$version] = [
            'memory_limit' => (string) $validated['memory_limit'],
            'upload_max_filesize' => (string) $validated['upload_max_filesize'],
            'post_max_size' => (string) $validated['post_max_size'],
            'max_execution_time' => (string) $validated['max_execution_time'],
            'max_input_vars' => (string) $validated['max_input_vars'],
            'display_errors' => (string) $validated['display_errors'],
            'log_errors' => (string) $validated['log_errors'],
            'allow_url_fopen' => (string) $validated['allow_url_fopen'],
        ];

        $this->writeState($state);

        return redirect()->route('php.manager', ['version' => $version])->with('success', "PHP config updated for {$version}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $raw = $this->readRawState();

        if (count($raw) === 0) {
            $raw = $this->readLegacyStateFromStorage();
            if (count($raw) > 0) {
                $this->writeRawState($raw);
            }
        }

        $fallbackVersions = $this->detectServerPhpVersions();
        if (count($fallbackVersions) === 0) {
            $fallbackVersions = self::DEFAULT_VERSIONS;
        }

        $versions = collect($raw['versions'] ?? $fallbackVersions)
            ->map(fn ($version) => trim((string) $version))
            ->filter(fn ($version) => preg_match('/^\d+\.\d+$/', $version) === 1)
            ->unique()
            ->sort(fn ($a, $b) => version_compare($b, $a))
            ->values()
            ->all();

        if (count($versions) === 0) {
            $versions = $fallbackVersions;
        }

        $defaultVersion = (string) ($raw['default_version'] ?? $versions[0]);
        if (! in_array($defaultVersion, $versions, true)) {
            $defaultVersion = $versions[0];
        }

        return [
            'versions' => $versions,
            'default_version' => $defaultVersion,
            'extensions' => $this->normalizeExtensions($raw['extensions'] ?? [], $versions),
            'config' => $this->normalizeConfig($raw['config'] ?? [], $versions),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $this->writeRawState($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function readRawState(): array
    {
        if (! DB::getSchemaBuilder()->hasTable(self::SETTINGS_TABLE)) {
            return [];
        }

        $row = DB::table(self::SETTINGS_TABLE)
            ->where('setting_key', self::STATE_SETTING_KEY)
            ->first();

        if ($row === null || ! isset($row->setting_value)) {
            return [];
        }

        $decoded = json_decode((string) $row->setting_value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readLegacyStateFromStorage(): array
    {
        if (! Storage::exists(self::LEGACY_STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::LEGACY_STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeRawState(array $state): void
    {
        if (! DB::getSchemaBuilder()->hasTable(self::SETTINGS_TABLE)) {
            return;
        }

        DB::table(self::SETTINGS_TABLE)->updateOrInsert(
            ['setting_key' => self::STATE_SETTING_KEY],
            [
                'setting_value' => json_encode($state, JSON_PRETTY_PRINT),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string> $versions
     * @return array<string, array<string, bool>>
     */
    private function normalizeExtensions(array $raw, array $versions): array
    {
        $extensions = [];

        foreach ($versions as $version) {
            $versionRaw = is_array($raw[$version] ?? null) ? $raw[$version] : [];
            $extensionNames = array_values(array_unique(array_merge(self::DEFAULT_EXTENSIONS, array_keys($versionRaw))));

            $extensions[$version] = collect($extensionNames)
                ->mapWithKeys(function ($extension) use ($versionRaw) {
                    return [(string) $extension => (bool) ($versionRaw[$extension] ?? true)];
                })
                ->all();
        }

        return $extensions;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string> $versions
     * @return array<string, array<string, string>>
     */
    private function normalizeConfig(array $raw, array $versions): array
    {
        $config = [];

        foreach ($versions as $version) {
            $versionRaw = is_array($raw[$version] ?? null) ? $raw[$version] : [];

            $config[$version] = collect(self::DEFAULT_CONFIG)
                ->mapWithKeys(function ($defaultValue, $key) use ($versionRaw) {
                    $value = $versionRaw[$key] ?? $defaultValue;

                    return [(string) $key => trim((string) $value) !== '' ? (string) $value : $defaultValue];
                })
                ->all();
        }

        return $config;
    }

    /**
     * @return array<int, string>
     */
    private function detectServerPhpVersions(): array
    {
        $versions = [];

        $currentVersion = $this->detectCurrentPhpVersion();
        if ($currentVersion !== '') {
            $versions[] = $currentVersion;
        }

        $versions = array_merge($versions, $this->detectUbuntuPhpVersions());

        $commandCandidates = [
            'php8.4',
            'php8.3',
            'php8.2',
            'php8.1',
            'php8.0',
            'php7.4',
            'php',
        ];

        foreach ($commandCandidates as $command) {
            $output = $this->runCommand($command.' -v');
            $version = $this->extractVersionFromText($output);
            if ($version !== '') {
                $versions[] = $version;
            }
        }

        $paths = [];
        $pathPatterns = [
            'C:\\wamp64\\bin\\php\\php*',
            'C:\\xampp\\php*',
            '/usr/bin/php*',
            '/usr/local/bin/php*',
            '/opt/php*',
            '/opt/cpanel/ea-php*/root/usr/bin/php',
        ];

        foreach ($pathPatterns as $pattern) {
            $matches = glob($pattern) ?: [];
            foreach ($matches as $match) {
                if (is_string($match)) {
                    $paths[] = $match;
                }
            }
        }

        foreach ($paths as $path) {
            $version = $this->extractVersionFromPath($path);
            if ($version !== '') {
                $versions[] = $version;
                continue;
            }

            if (is_file($path) && is_executable($path)) {
                $output = $this->runCommand('"'.$path.'" -v');
                $parsed = $this->extractVersionFromText($output);
                if ($parsed !== '') {
                    $versions[] = $parsed;
                }
            }
        }

        return collect($versions)
            ->filter(fn ($version) => preg_match('/^\d+\.\d+$/', (string) $version) === 1)
            ->unique()
            ->sort(fn ($a, $b) => version_compare((string) $b, (string) $a))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function detectUbuntuPhpVersions(): array
    {
        $versions = [];

        $alternatives = $this->runCommand('update-alternatives --list php');
        $versions = array_merge($versions, $this->extractVersionsFromText($alternatives));

        $binList = $this->runCommand('ls /usr/bin/php* 2>/dev/null');
        $versions = array_merge($versions, $this->extractVersionsFromText($binList));

        $dpkgList = $this->runCommand("dpkg -l | grep -E 'php[0-9]+\\.[0-9]+-(cli|fpm|common)'");
        $versions = array_merge($versions, $this->extractVersionsFromText($dpkgList));

        return collect($versions)
            ->filter(fn ($version) => preg_match('/^\d+\.\d+$/', (string) $version) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function detectExtensionsForVersion(string $version): array
    {
        $binary = $this->resolvePhpBinaryForVersion($version);
        if ($binary === '') {
            return [];
        }

        $output = $this->runCommand($binary.' -m');
        if ($output === '') {
            return [];
        }

        $extensions = collect(preg_split('/\r\n|\r|\n/', $output) ?: [])
            ->map(fn ($line) => strtolower(trim((string) $line)))
            ->filter(fn ($line) => $line !== '' && preg_match('/^[a-z0-9_]+$/', $line) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $extensions;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<int, string> $versions
     * @return array<string, array<string, bool>>
     */
    private function syncAllExtensionsFromServer(array $existing, array $versions): array
    {
        $normalizedExisting = $this->normalizeExtensions($existing, $versions);
        $synced = [];

        foreach ($versions as $version) {
            $detected = $this->detectExtensionsForVersion($version);
            if (count($detected) === 0) {
                $synced[$version] = $normalizedExisting[$version];
                continue;
            }

            $synced[$version] = collect($detected)
                ->mapWithKeys(fn ($extension) => [(string) $extension => true])
                ->all();
        }

        return $synced;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<int, string> $versions
     * @return array<string, array<string, string>>
     */
    private function syncConfigFromServer(array $existing, array $versions): array
    {
        $normalizedExisting = $this->normalizeConfig($existing, $versions);
        $synced = [];

        foreach ($versions as $version) {
            $detected = $this->detectConfigForVersion($version);
            $synced[$version] = array_merge($normalizedExisting[$version], $detected);
        }

        return $synced;
    }

    /**
     * @return array<string, string>
     */
    private function detectConfigForVersion(string $version): array
    {
        $binary = $this->resolvePhpBinaryForVersion($version);
        if ($binary === '') {
            return [];
        }

        $output = $this->runCommand($binary.' -i');
        if ($output === '') {
            return [];
        }

        return [
            'memory_limit' => $this->extractIniValue($output, 'memory_limit', self::DEFAULT_CONFIG['memory_limit']),
            'upload_max_filesize' => $this->extractIniValue($output, 'upload_max_filesize', self::DEFAULT_CONFIG['upload_max_filesize']),
            'post_max_size' => $this->extractIniValue($output, 'post_max_size', self::DEFAULT_CONFIG['post_max_size']),
            'max_execution_time' => $this->extractIniValue($output, 'max_execution_time', self::DEFAULT_CONFIG['max_execution_time']),
            'max_input_vars' => $this->extractIniValue($output, 'max_input_vars', self::DEFAULT_CONFIG['max_input_vars']),
            'display_errors' => $this->normalizeOnOff($this->extractIniValue($output, 'display_errors', self::DEFAULT_CONFIG['display_errors'])),
            'log_errors' => $this->normalizeOnOff($this->extractIniValue($output, 'log_errors', self::DEFAULT_CONFIG['log_errors'])),
            'allow_url_fopen' => $this->normalizeOnOff($this->extractIniValue($output, 'allow_url_fopen', self::DEFAULT_CONFIG['allow_url_fopen'])),
        ];
    }

    private function resolvePhpBinaryForVersion(string $version): string
    {
        $digits = str_replace('.', '', $version);

        $directPaths = [
            "C:\\wamp64\\bin\\php\\php{$version}\\php.exe",
            "C:\\wamp64\\bin\\php\\php{$version}.*\\php.exe",
            "C:\\xampp\\php{$version}\\php.exe",
            "/usr/bin/php{$version}",
            "/usr/local/bin/php{$version}",
            "/opt/cpanel/ea-php{$digits}/root/usr/bin/php",
        ];

        foreach ($directPaths as $pathPattern) {
            $matches = str_contains($pathPattern, '*') ? (glob($pathPattern) ?: []) : [$pathPattern];
            foreach ($matches as $match) {
                if (! is_string($match)) {
                    continue;
                }

                if (is_file($match)) {
                    return '"'.$match.'"';
                }
            }
        }

        $commandCandidates = [
            "php{$version}",
            "php{$digits}",
            "php{$version}.exe",
            "php{$digits}.exe",
            'php',
        ];

        foreach ($commandCandidates as $command) {
            $output = $this->runCommand($command.' -v');
            $detected = $this->extractVersionFromText($output);
            if ($detected === $version) {
                return $command;
            }
        }

        return '';
    }

    private function detectCurrentPhpVersion(): string
    {
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        return preg_match('/^\d+\.\d+$/', $version) === 1 ? $version : '';
    }

    private function runCommand(string $command): string
    {
        try {
            $output = shell_exec($command.' 2>&1');

            return is_string($output) ? $output : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractVersionFromText(string $text): string
    {
        if (preg_match('/PHP\s+(\d+\.\d+)\.\d+/i', $text, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('/\b(\d+\.\d+)\b/', $text, $matches) === 1) {
            return (string) $matches[1];
        }

        return '';
    }

    private function extractVersionFromPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/php(?:-)?(\d+\.\d+)/i', $normalized, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('/php(\d)(\d)/i', $normalized, $matches) === 1) {
            return "{$matches[1]}.{$matches[2]}";
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function extractVersionsFromText(string $text): array
    {
        preg_match_all('/(?:php|ea-php)(\d+)\.?(\d+)?/i', $text, $matches, PREG_SET_ORDER);
        $versions = [];

        foreach ($matches as $match) {
            $major = isset($match[1]) ? (string) $match[1] : '';
            $minor = isset($match[2]) ? (string) $match[2] : '';
            if ($major === '') {
                continue;
            }

            if ($minor === '' && strlen($major) === 2) {
                $versions[] = $major[0].'.'.$major[1];
                continue;
            }

            if ($minor !== '') {
                $versions[] = $major.'.'.$minor;
            }
        }

        preg_match_all('/\b(\d+\.\d+)\b/', $text, $dotMatches, PREG_SET_ORDER);
        foreach ($dotMatches as $match) {
            if (isset($match[1])) {
                $versions[] = (string) $match[1];
            }
        }

        return collect($versions)->unique()->values()->all();
    }

    private function extractIniValue(string $phpInfo, string $key, string $fallback): string
    {
        $quoted = preg_quote($key, '/');
        if (preg_match('/^'.$quoted.'\s*=>\s*([^\n=>]+)\s*=>/mi', $phpInfo, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/^'.$quoted.'\s*=>\s*([^\n]+)$/mi', $phpInfo, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $fallback;
    }

    private function normalizeOnOff(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'on', 'true', 'yes'], true) ? 'On' : 'Off';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildManagerPayload(array $state, string $selectedVersion): array
    {
        if (! in_array($selectedVersion, $state['versions'], true)) {
            $selectedVersion = $state['default_version'];
        }

        $availableExtensions = $this->detectExtensionsForVersion($selectedVersion);
        if (count($availableExtensions) === 0) {
            $availableExtensions = self::DEFAULT_EXTENSIONS;
        }

        $extensionStates = $state['extensions'][$selectedVersion] ?? [];
        foreach ($availableExtensions as $extension) {
            if (! array_key_exists($extension, $extensionStates)) {
                $extensionStates[$extension] = true;
            }
        }

        return [
            'installedVersions' => $state['versions'],
            'defaultVersion' => $state['default_version'],
            'candidateVersions' => self::CANDIDATE_VERSIONS,
            'selectedVersion' => $selectedVersion,
            'availableExtensions' => $availableExtensions,
            'extensionStates' => $extensionStates,
            'configValues' => $state['config'][$selectedVersion] ?? self::DEFAULT_CONFIG,
        ];
    }
}
