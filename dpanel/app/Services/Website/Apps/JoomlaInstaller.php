<?php

namespace App\Services\Website\Apps;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Joomla: Full Package zip from GitHub, then installation/joomla.php install. */
class JoomlaInstaller extends BaseAppInstaller
{
    /** Minimum and maximum PHP for each Joomla major; unknown majors use the last entry's range. */
    public const PHP = [
        '4' => ['min_php' => '7.2', 'max_php' => '8.3'],
        '5' => ['min_php' => '8.1', 'max_php' => '8.5'],
        '6' => ['min_php' => '8.3', 'max_php' => '8.5'],
    ];

    public function key(): string
    {
        return 'joomla';
    }

    public function definition(): array
    {
        return [
            'label' => 'Joomla',
            'description' => 'Joomla CMS, installed with an administrator account and database.',
            'document_root' => '',
            'database' => 'required',
            'engines' => [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL],
            'fields' => ['site_name', 'admin_name', 'admin_username', 'admin_email', 'admin_password'],
        ];
    }

    public function rules(): array
    {
        return [
            'site_name' => self::SITE_NAME,
            'admin_name' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'admin_username' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'admin_email' => self::ADMIN_EMAIL,
            'admin_password' => self::ADMIN_PASSWORD,
        ];
    }

    public function versions(): array
    {
        try {
            $releases = $this->releases();
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Could not load Joomla releases from GitHub: '.$e->getMessage());
        }

        return array_map(fn (array $release): array => [
            'value' => $release['version'],
            'label' => 'Joomla '.$release['version'],
            ...$this->phpRange($release['version']),
        ], $releases);
    }

    /**
     * Newest stable Full Package of each Joomla major, newest major first.
     *
     * @return array<int, array{version: string, url: string, sha256: string|null}>
     */
    public function releases(): array
    {
        return Cache::remember('app-installer.joomla.releases', now()->addHours(6), function (): array {
            return $this->pickReleases($this->support->githubReleases('joomla/joomla-cms', 'app-installer.joomla.github'));
        });
    }

    /**
     * @param  array<int, mixed>  $releases  GitHub releases API payload
     * @return array<int, array{version: string, url: string, sha256: string|null}>
     */
    public function pickReleases(array $releases): array
    {
        $byMajor = [];
        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $url = (string) ($asset['browser_download_url'] ?? '');
                if (preg_match('/^Joomla_(\d+)\.(\d+)\.(\d+)-Stable-Full_Package\.zip$/', $name, $m) !== 1
                    || ! str_starts_with($url, 'https://github.com/joomla/joomla-cms/releases/download/')) {
                    continue;
                }
                $version = "{$m[1]}.{$m[2]}.{$m[3]}";
                if (! isset($byMajor[$m[1]]) || version_compare($version, $byMajor[$m[1]]['version'], '>')) {
                    $byMajor[$m[1]] = ['version' => $version, 'url' => $url, 'sha256' => AppInstallSupport::assetSha256((array) $asset)];
                }
            }
        }
        krsort($byMajor, SORT_NUMERIC);

        return array_values($byMajor);
    }

    /** @return array{min_php: string, max_php: string} */
    public function phpRange(string $version): array
    {
        return self::rangeFor(self::PHP, explode('.', $version)[0]);
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        $version = (string) ($input['version'] ?? '');
        $package = collect($this->releases())->firstWhere('version', $version);
        if ($package === null) {
            throw new AppInstallFailed('The selected Joomla version is not available.');
        }

        return ['range' => $this->phpRange($version), 'label' => 'Joomla '.$version, 'package' => $package];
    }

    public function download(AppInstallContext $context): array
    {
        try {
            $tmp = $this->support->download($context->plan['package']['url'], $context->plan['package']['sha256'] ?? null);
        } catch (AppInstallFailed $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
        try {
            return $this->support->uploadArchive($context->siteOwner, $context->rootPath, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function configure(AppInstallContext $context): void
    {
        $database = $context->database;
        $this->run($context, 'joomla_install', ['joomla' => [
            'site_name' => $context->input('site_name', $context->domain),
            'admin_user' => $context->input('admin_name', 'Administrator'),
            'admin_username' => $context->input('admin_username'),
            'admin_password' => $context->input('admin_password'),
            'admin_email' => $context->input('admin_email'),
            'db_type' => $context->isPostgresql ? 'pgsql' : 'mysqli',
            // Joomla's PDO driver splits host:port itself.
            'db_host' => $context->isPostgresql ? $database['database_host'].':'.$database['database_port'] : $database['database_host'],
            'db_user' => $database['database_user'],
            'db_pass' => $database['database_password'],
            'db_name' => $database['database_name'],
            'db_prefix' => 'j'.Str::lower(Str::random(4)).'_',
        ]], 'Joomla installation');
        // The web installer must not stay reachable once the site is set up.
        $this->removeIfPresent($context, 'installation');
    }

    public function complete(AppInstallContext $context): string
    {
        return " Log in at /administrator as {$context->input('admin_username')}.";
    }
}
