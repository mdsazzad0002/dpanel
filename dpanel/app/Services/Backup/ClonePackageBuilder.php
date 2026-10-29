<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Models\Website;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Builds the portable clone package (files.zip + database.sql + manifest.json)
 * used by both cross-server flows: Share (the other server pulls it from a
 * download link) and Push to Live (this server uploads it to the other one).
 * dRust builds the whole package as root (WebsiteArchiver::clonePackage):
 * each site's files belong to its own system user, which the panel's PHP
 * worker can't read or zip itself.
 */
class ClonePackageBuilder
{
    public function __construct(private readonly WebsiteArchiver $archiver) {}

    /**
     * @param  Closure(string):void|null  $onStage  called with 'archiving' when dRust starts
     * @return array{path:string,has_database:bool,warning:string}
     */
    public function build(User $user, Website $website, string $packagePath, ?Closure $onStage = null): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            throw new RuntimeException('dRust backup API is not configured.');
        }

        $onStage?->__invoke('archiving');
        $result = $this->archiver->clonePackage($user, $website, $baseUrl, $apiToken, $packagePath);
        if (! $result['ok'] || ! is_file($packagePath)) {
            File::delete($packagePath);
            throw new RuntimeException($result['message'] ?: 'The clone package could not be created.');
        }

        return ['path' => $packagePath, 'has_database' => (bool) ($result['has_database'] ?? false), 'warning' => (string) ($result['warning'] ?? '')];
    }
}
