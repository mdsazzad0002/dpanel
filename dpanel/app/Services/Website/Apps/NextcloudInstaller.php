<?php

namespace App\Services\Website\Apps;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nextcloud server zip from download.nextcloud.com, checked against the
 * published SHA-256, then `occ maintenance:install`. The data folder sits next
 * to the site root: the gateway does not read .htaccess, so data inside the
 * web root would be downloadable.
 */
class NextcloudInstaller extends BaseAppInstaller
{
    public const RELEASES_URL = 'https://download.nextcloud.com/server/releases/';

    /** PHP per Nextcloud major; newer majors use the last entry. */
    public const PHP = [
        '30' => ['min_php' => '8.1', 'max_php' => '8.3'],
        '31' => ['min_php' => '8.1', 'max_php' => '8.4'],
        '32' => ['min_php' => '8.2', 'max_php' => '8.4'],
        '33' => ['min_php' => '8.2', 'max_php' => '8.4'],
    ];

    private const MODULES = ['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'mbstring', 'openssl', 'posix', 'simplexml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'];

    public function key(): string
    {
        return 'nextcloud';
    }

    /** What Nextcloud's root .htaccess answers with 404. */
    public function deniedPaths(): array
    {
        return ['/3rdparty', '/build', '/config', '/console.php', '/lib', '/occ', '/templates', '/tests'];
    }

    public function definition(): array
    {
        return [
            'label' => 'Nextcloud',
            'description' => 'Nextcloud file sync and sharing, with an admin account, a database, its data folder outside the web root and a background-job cron.',
            'document_root' => '',
            'database' => 'new',
            'engines' => [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL],
            'fields' => ['admin_username', 'admin_email', 'admin_password'],
            'password_file' => 'config/config.php',
        ];
    }

    public function rules(): array
    {
        return [
            'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'admin_email' => self::ADMIN_EMAIL,
            'admin_password' => self::ADMIN_PASSWORD,
        ];
    }

    public function versions(): array
    {
        try {
            $versions = Cache::remember('app-installer.nextcloud.releases', now()->addHours(6), function (): array {
                $response = Http::timeout(30)->withHeaders(['User-Agent' => 'dPanel'])->get(self::RELEASES_URL);
                if (! $response->successful()) {
                    throw new \RuntimeException('HTTP '.$response->status());
                }

                return $this->pickReleases($response->body());
            });
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Could not load Nextcloud releases: '.$e->getMessage());
        }

        return array_map(fn (string $version): array => [
            'value' => $version,
            'label' => 'Nextcloud '.$version,
            ...self::rangeFor(self::PHP, explode('.', $version)[0]),
        ], $versions);
    }

    /**
     * Newest stable release of the three newest supported majors, from the
     * download server's file index.
     *
     * @return array<int, string>
     */
    public function pickReleases(string $index): array
    {
        preg_match_all('/nextcloud-(\d+)\.(\d+)\.(\d+)\.zip(?![.\w])/', $index, $matches, PREG_SET_ORDER);
        $oldest = (int) array_key_first(self::PHP);
        $candidates = [];
        foreach ($matches as $m) {
            if ((int) $m[1] >= $oldest) {
                $candidates[] = ['series' => $m[1], 'version' => "{$m[1]}.{$m[2]}.{$m[3]}"];
            }
        }

        return array_column(array_slice(self::newestPerSeries($candidates), 0, 3), 'version');
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        $version = (string) ($input['version'] ?? '');
        if (! in_array($version, array_column($this->versions(), 'value'), true)) {
            throw new AppInstallFailed('The selected Nextcloud version is not available.');
        }

        return ['range' => self::rangeFor(self::PHP, explode('.', $version)[0]), 'label' => 'Nextcloud '.$version, 'package' => $version];
    }

    public function preflight(AppInstallContext $context): void
    {
        $this->support->requireModules($context, 'Nextcloud', [...self::MODULES, $context->isPostgresql ? 'pdo_pgsql' : 'pdo_mysql']);
        $this->support->requireAbsent($this->dataDirectory($context), 'The Nextcloud data folder');
    }

    public function download(AppInstallContext $context): array
    {
        $url = self::RELEASES_URL.'nextcloud-'.$context->plan['package'].'.zip';
        try {
            $tmp = $this->support->download($url, $this->support->fetchSha256($url.'.sha256'), 10 * 1024 * 1024);
        } catch (AppInstallFailed $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
        try {
            $base = AppInstallSupport::zipBase($tmp, 'occ');
            if ($base === null) {
                return ['success' => false, 'output' => 'The download is not a Nextcloud server package.'];
            }

            return $this->support->deployArchiveDirectory($context->siteOwner, $context->rootPath, $tmp, $base);
        } finally {
            @unlink($tmp);
        }
    }

    public function configure(AppInstallContext $context): void
    {
        $database = $context->database;
        $this->run($context, 'nextcloud_install', ['nextcloud' => [
            'admin_user' => $context->input('admin_username'),
            'admin_pass' => $context->input('admin_password'),
            'admin_email' => $context->input('admin_email'),
            'db_type' => $context->isPostgresql ? 'pgsql' : 'mysql',
            'db_host' => $database['database_host'].':'.$database['database_port'],
            'db_user' => $database['database_user'],
            'db_pass' => $database['database_password'],
            'db_name' => $database['database_name'],
            'data_dir' => $this->dataDirectory($context),
            'domain' => $context->domain,
            'url' => $context->siteUrl(),
        ]], 'Nextcloud installation');
    }

    public function complete(AppInstallContext $context): string
    {
        $cron = $this->support->addCron(
            $context->website,
            'Nextcloud background jobs',
            '*/5 * * * *',
            [$this->support->phpCli($context->phpVersion), '-f', $context->rootPath.'/cron.php'],
            'Added by the Nextcloud installer.',
            'The Nextcloud background-job cron (every 5 minutes) was added.',
            'Add the cron job yourself (every 5 minutes)',
        );

        return " Log in at / as {$context->input('admin_username')}. Files are stored in {$this->dataDirectory($context)}.".$cron;
    }

    public function dataDirectory(AppInstallContext $context): string
    {
        return $context->siblingPath('nextcloud-data');
    }
}
