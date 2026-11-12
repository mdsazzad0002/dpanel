<?php

namespace App\Services\Website\Apps;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;

/** Drupal recommended project with Drush, then drush site:install. */
class DrupalInstaller extends BaseAppInstaller
{
    public const VERSIONS = [
        '11' => ['label' => 'Drupal 11', 'min_php' => '8.3', 'max_php' => '8.5'],
        '10' => ['label' => 'Drupal 10', 'min_php' => '8.1', 'max_php' => '8.4'],
    ];

    public function key(): string
    {
        return 'drupal';
    }

    /** Drupal writes an .htaccess into the public files folder that stops PHP there. */
    public function noPhpPaths(): array
    {
        return ['/sites/default/files'];
    }

    public function definition(): array
    {
        return [
            'label' => 'Drupal',
            'description' => 'Drupal CMS (recommended project) with Drush, installed with the standard profile and an admin account.',
            'document_root' => 'web',
            'database' => 'required',
            'engines' => [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL],
            'fields' => ['site_name', 'admin_username', 'admin_email', 'admin_password'],
        ];
    }

    public function rules(): array
    {
        return [
            'site_name' => self::SITE_NAME,
            'admin_username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'admin_email' => self::ADMIN_EMAIL,
            'admin_password' => self::ADMIN_PASSWORD,
        ];
    }

    public function versions(): array
    {
        return collect(self::VERSIONS)->map(fn (array $meta, string $version): array => [
            'value' => (string) $version,
            'label' => $meta['label'],
            'min_php' => $meta['min_php'],
            'max_php' => $meta['max_php'],
        ])->values()->all();
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        $version = (string) ($input['version'] ?? '');
        if (! isset(self::VERSIONS[$version])) {
            throw new AppInstallFailed('The selected Drupal version is not available.');
        }
        $meta = self::VERSIONS[$version];

        return ['range' => ['min_php' => $meta['min_php'], 'max_php' => $meta['max_php']], 'label' => $meta['label'], 'package' => $version];
    }

    public function download(AppInstallContext $context): array
    {
        $version = $context->plan['package'];
        $download = $this->support->step($context, 'create_project', ['stack' => 'drupal', 'version' => $version], 960);
        if ($download['success']) {
            $download = $this->support->step($context, 'drupal_drush', ['version' => $version], 960);
        }

        return $download;
    }

    public function afterDatabase(AppInstallContext $context): void
    {
        if (! $context->isPostgresql) {
            return;
        }
        try {
            $this->ensurePgTrgm($context->database);
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Drupal needs the pg_trgm PostgreSQL extension, and creating it failed: '.$e->getMessage());
        }
    }

    public function configure(AppInstallContext $context): void
    {
        try {
            // settings.php carries the connection, so the password never has
            // to be passed to Drush on the command line.
            $dir = $context->rootPath.'/web/sites/default';
            $template = $this->support->filemanager->readTextFile($context->siteOwner, $dir.'/default.settings.php')['content'];
            $this->support->filemanager->writeTextFile($context->siteOwner, $dir.'/settings.php', $this->settings($template, $context->database));
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Writing settings.php failed: '.$e->getMessage());
        }
        // site:install drops every table in the database first, so a reused
        // database starts clean.
        $this->run($context, 'drupal_install', ['drupal' => [
            'site_name' => $context->input('site_name', $context->domain),
            'account_name' => $context->input('admin_username'),
            'account_pass' => $context->input('admin_password'),
            'account_mail' => $context->input('admin_email'),
        ]], 'Drupal installation');
    }

    public function complete(AppInstallContext $context): string
    {
        return " Log in at /user/login as {$context->input('admin_username')}.";
    }

    /**
     * @param  array<string, string>  $database
     */
    public function settings(string $template, array $database): string
    {
        $isPostgresql = ($database['engine'] ?? '') === DatabaseRequest::ENGINE_POSTGRESQL;
        $connection = var_export([
            'database' => $database['database_name'],
            'username' => $database['database_user'],
            'password' => $database['database_password'],
            'host' => $database['database_host'],
            'port' => $database['database_port'],
            'prefix' => '',
            ...($isPostgresql ? [
                'driver' => 'pgsql',
                'namespace' => 'Drupal\\pgsql\\Driver\\Database\\pgsql',
                'autoload' => 'core/modules/pgsql/src/Driver/Database/pgsql/',
            ] : [
                'driver' => 'mysql',
                'collation' => 'utf8mb4_general_ci',
                'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
                'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
            ]),
        ], true);

        return rtrim($template)."\n\n// Added by the dPanel installer.\n\$databases['default']['default'] = {$connection};\n";
    }

    /**
     * Drupal 10+ refuses PostgreSQL without pg_trgm. It is a trusted extension
     * (PostgreSQL 13+), so the database owner can create it.
     *
     * @param  array<string, string>  $database
     */
    private function ensurePgTrgm(array $database): void
    {
        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $database['database_host'], $database['database_port'], $database['database_name']),
            $database['database_user'],
            $database['database_password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10],
        );
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }
}
