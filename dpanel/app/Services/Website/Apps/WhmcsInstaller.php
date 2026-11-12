<?php

namespace App\Services\Website\Apps;

use App\Models\User;
use App\Models\Website;
use App\Services\Website\AppPackageUploads;
use Illuminate\Support\Str;

/**
 * WHMCS is licensed software behind a customer login, so the admin uploads
 * the zip from their WHMCS account and enters the license key.
 */
class WhmcsInstaller extends BaseAppInstaller
{
    /** WHMCS 8.x/9.x run on PHP 8.1–8.3 and need the matching ionCube Loader. */
    public const PHP = ['min_php' => '8.1', 'max_php' => '8.3'];

    public function __construct(AppInstallSupport $support, private readonly AppPackageUploads $uploads)
    {
        parent::__construct($support);
    }

    public function key(): string
    {
        return 'whmcs';
    }

    public function definition(): array
    {
        return [
            'label' => 'WHMCS',
            'description' => 'WHMCS billing from your own licensed package, with admin account, database and cron.',
            'document_root' => '',
            'database' => 'new',
            'upload' => true,
            'fields' => [],
        ];
    }

    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'uuid'],
            'license_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'admin_password' => self::ADMIN_PASSWORD,
        ];
    }

    public function versions(): array
    {
        return [['value' => 'upload', 'label' => 'Uploaded WHMCS package', ...self::PHP]];
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        try {
            $zipPath = $this->uploads->packagePath((string) ($input['upload_id'] ?? ''), (string) $website->id, (int) $actor?->id);
        } catch (\Throwable $e) {
            throw new AppInstallFailed($e->getMessage());
        }
        $base = $this->uploads->whmcsBaseDirectory($zipPath);
        if ($base === null) {
            throw new AppInstallFailed('The uploaded file is not a WHMCS package (install/bin/installer.php and init.php were not found).');
        }

        return ['range' => self::PHP, 'label' => 'WHMCS', 'package' => ['path' => $zipPath, 'base' => $base]];
    }

    /** WHMCS is ionCube-encoded; without the loader it cannot even install. */
    public function preflight(AppInstallContext $context): void
    {
        $modules = $this->support->step($context, 'php_modules', [], 60);
        if (! $modules['success'] || stripos($modules['output'], 'ioncube') === false) {
            throw new AppInstallFailed("WHMCS needs the ionCube Loader for PHP {$context->phpVersion}, and it is not loaded. Install ionCube for PHP {$context->phpVersion} and try again.");
        }
    }

    /** WHMCS zips keep everything under a top-level whmcs/ folder. */
    public function download(AppInstallContext $context): array
    {
        $package = $context->plan['package'];

        return $this->support->deployArchiveDirectory($context->siteOwner, $context->rootPath, $package['path'], $package['base']);
    }

    public function configure(AppInstallContext $context): void
    {
        $filemanager = $this->support->filemanager;
        try {
            // The CLI installer writes into configuration.php, which the
            // package only ships as a template.
            if (! $filemanager->fileExists($context->rootPath.'/configuration.php')
                && $filemanager->fileExists($context->rootPath.'/configuration.php.new')) {
                $filemanager->copyPath($context->siteOwner, $context->rootPath.'/configuration.php.new', $context->rootPath.'/configuration.php');
            }
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Preparing configuration.php failed: '.$e->getMessage());
        }
        $database = $context->database;
        $this->run($context, 'whmcs_install', ['whmcs' => [
            'admin_username' => $context->input('admin_username'),
            'admin_password' => $context->input('admin_password'),
            'license' => $context->input('license_key'),
            'db_host' => $database['database_host'],
            'db_username' => $database['database_user'],
            'db_password' => $database['database_password'],
            'db_name' => $database['database_name'],
            'cc_encryption_hash' => Str::random(64),
        ]], 'WHMCS installation');
        $this->removeIfPresent($context, 'install');
    }

    public function complete(AppInstallContext $context): string
    {
        $cron = $this->support->addCron(
            $context->website,
            'WHMCS automation',
            '*/5 * * * *',
            [$this->support->phpCli($context->phpVersion), '-q', $context->rootPath.'/crons/cron.php'],
            'Added by the WHMCS installer.',
            'The WHMCS cron job (every 5 minutes) was added.',
            'Add the cron job yourself (every 5 minutes)',
        );
        $this->uploads->delete($context->input('upload_id'));

        return " Log in at /admin as {$context->input('admin_username')}.".$cron;
    }
}
