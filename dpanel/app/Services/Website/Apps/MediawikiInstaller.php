<?php

namespace App\Services\Website\Apps;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MediaWiki tarball from releases.wikimedia.org. Wikimedia publishes GPG
 * signatures (no checksum file), so the tarball is verified with gpg against
 * the release keys on www.mediawiki.org, then maintenance/install.php runs.
 */
class MediawikiInstaller extends BaseAppInstaller
{
    public const RELEASES_URL = 'https://releases.wikimedia.org/mediawiki/';

    public const KEYS_URL = 'https://www.mediawiki.org/keys/keys.txt';

    /** Supported branches and their PHP range, newest first. */
    public const BRANCHES = [
        '1.45' => ['min_php' => '8.2', 'max_php' => '8.4'],
        '1.44' => ['min_php' => '8.1', 'max_php' => '8.4'],
        '1.43' => ['min_php' => '8.1', 'max_php' => '8.4'],
    ];

    private const MODULES = ['ctype', 'dom', 'fileinfo', 'iconv', 'intl', 'json', 'mbstring', 'xml'];

    public function key(): string
    {
        return 'mediawiki';
    }

    public function definition(): array
    {
        return [
            'label' => 'MediaWiki',
            'description' => 'MediaWiki, the wiki engine behind Wikipedia, installed with an admin account and a database.',
            'document_root' => '',
            'database' => 'new',
            'engines' => [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL],
            'fields' => ['site_name', 'admin_username', 'admin_password'],
            'password_file' => 'LocalSettings.php',
        ];
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:200', 'regex:/^[^#<>\[\]|{}\x00-\x1F\x7F]+$/u'],
            'admin_username' => ['required', 'string', 'max:85', 'regex:/^[A-Za-z][A-Za-z0-9_. -]*$/'],
            'admin_password' => self::ADMIN_PASSWORD,
        ];
    }

    public function versions(): array
    {
        $versions = [];
        foreach (self::BRANCHES as $branch => $range) {
            $version = $this->latestPointRelease((string) $branch);
            if ($version !== null) {
                $versions[] = ['value' => $version, 'label' => 'MediaWiki '.$version, ...$range];
            }
        }
        if ($versions === []) {
            throw new AppInstallFailed('Could not load MediaWiki releases from releases.wikimedia.org.');
        }

        return $versions;
    }

    private function latestPointRelease(string $branch): ?string
    {
        try {
            return Cache::remember("app-installer.mediawiki.{$branch}", now()->addHours(6), function () use ($branch): ?string {
                $response = Http::timeout(20)->withHeaders(['User-Agent' => 'dPanel'])->get(self::RELEASES_URL.$branch.'/');

                return $response->successful() ? $this->pickRelease($branch, $response->body()) : null;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** Newest point release of $branch in a releases.wikimedia.org index. */
    public function pickRelease(string $branch, string $index): ?string
    {
        preg_match_all('/mediawiki-('.preg_quote($branch, '/').'\.\d+)\.tar\.gz(?![.\w])/', $index, $matches);
        $versions = array_unique($matches[1]);
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0] ?? null;
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        $version = (string) ($input['version'] ?? '');
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        if (! isset(self::BRANCHES[$branch]) || $this->latestPointRelease($branch) !== $version) {
            throw new AppInstallFailed('The selected MediaWiki version is not available.');
        }

        return ['range' => self::BRANCHES[$branch], 'label' => 'MediaWiki '.$version, 'package' => ['branch' => $branch, 'version' => $version]];
    }

    /** MediaWiki talks to MariaDB through mysqli and to PostgreSQL through pgsql. */
    public function postgresModule(): string
    {
        return 'pgsql';
    }

    public function preflight(AppInstallContext $context): void
    {
        $this->support->requireModules($context, 'MediaWiki', [...self::MODULES, $context->isPostgresql ? 'pgsql' : 'mysqli']);
    }

    public function download(AppInstallContext $context): array
    {
        ['branch' => $branch, 'version' => $version] = $context->plan['package'];
        $url = self::RELEASES_URL."{$branch}/mediawiki-{$version}.tar.gz";
        $tmp = null;
        try {
            $tmp = $this->support->download($url, null, 10 * 1024 * 1024, '.tar.gz');
            $this->support->verifySignature($tmp, $url.'.sig', self::KEYS_URL);

            return $this->support->deployArchiveDirectory($context->siteOwner, $context->rootPath, $tmp, "mediawiki-{$version}/");
        } catch (AppInstallFailed $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    public function configure(AppInstallContext $context): void
    {
        $database = $context->database;
        $this->run($context, 'mediawiki_install', ['mediawiki' => [
            'site_name' => $context->input('site_name', $context->domain),
            'admin_user' => $context->input('admin_username'),
            'admin_pass' => $context->input('admin_password'),
            'server' => $context->siteUrl(),
            'db_type' => $context->isPostgresql ? 'postgres' : 'mysql',
            'db_server' => $database['database_host'],
            'db_port' => $database['database_port'],
            'db_user' => $database['database_user'],
            'db_pass' => $database['database_password'],
            'db_name' => $database['database_name'],
        ]], 'MediaWiki installation');
    }

    public function complete(AppInstallContext $context): string
    {
        return " Log in at /index.php?title=Special:UserLogin as {$context->input('admin_username')}.";
    }
}
