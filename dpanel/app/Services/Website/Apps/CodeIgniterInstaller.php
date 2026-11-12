<?php

namespace App\Services\Website\Apps;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;

/** CodeIgniter 4 appstarter from Composer, with .env written from the shipped template. */
class CodeIgniterInstaller extends BaseAppInstaller
{
    public const PHP = ['min_php' => '8.1', 'max_php' => '8.5'];

    public function key(): string
    {
        return 'codeigniter';
    }

    public function definition(): array
    {
        return [
            'label' => 'CodeIgniter 4',
            'description' => 'CodeIgniter 4 app starter from Composer, with .env configured.',
            'document_root' => 'public',
            'database' => 'optional',
            'engines' => [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL],
            'fields' => [],
        ];
    }

    public function versions(): array
    {
        return [['value' => '4', 'label' => 'CodeIgniter 4 (latest)', ...self::PHP]];
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        return ['range' => self::PHP, 'label' => 'CodeIgniter 4', 'package' => '4'];
    }

    /** CodeIgniter's Postgre driver uses native pgsql. */
    public function postgresModule(): string
    {
        return 'pgsql';
    }

    public function download(AppInstallContext $context): array
    {
        return $this->support->step($context, 'create_project', ['stack' => 'codeigniter', 'version' => '4'], 960);
    }

    public function configure(AppInstallContext $context): void
    {
        try {
            $filemanager = $this->support->filemanager;
            $template = $filemanager->fileExists($context->rootPath.'/env')
                ? $filemanager->readTextFile($context->siteOwner, $context->rootPath.'/env')['content']
                : '';
            $values = [
                'CI_ENVIRONMENT' => 'production',
                'app.baseURL' => $context->siteUrl().'/',
                'encryption.key' => 'hex2bin:'.bin2hex(random_bytes(32)),
            ];
            $database = $context->database;
            if ($database !== null) {
                $values += [
                    'database.default.hostname' => $database['database_host'],
                    'database.default.database' => $database['database_name'],
                    'database.default.username' => $database['database_user'],
                    'database.default.password' => $database['database_password'],
                    'database.default.DBDriver' => ($database['engine'] ?? '') === DatabaseRequest::ENGINE_POSTGRESQL ? 'Postgre' : 'MySQLi',
                    'database.default.port' => $database['database_port'],
                ];
            }
            $filemanager->writeTextFile($context->siteOwner, $context->rootPath.'/.env', $this->setEnvValues($template, $values));
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Writing .env failed: '.$e->getMessage());
        }
    }

    /**
     * CodeIgniter's env template has commented `# key = value` lines; enable
     * and set them, or append missing keys.
     *
     * @param  array<string, string>  $values
     */
    public function setEnvValues(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.' = '.$this->envValue($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=.*$/m';
            $content = preg_match($pattern, $content) === 1
                ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1)
                : rtrim($content)."\n".$line."\n";
        }

        return $content;
    }

    private function envValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:\/@-]*$/', $value) === 1) {
            return $value;
        }
        // CodeIgniter's DotEnv reads single-quoted values literally.
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }
        // Double quotes unescape \\ and \" but expand ${VAR} with no way to escape it.
        if (str_contains($value, '${')) {
            throw new \InvalidArgumentException('Value cannot be written to CodeIgniter .env (contains both \' and ${).');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
