<?php

namespace App\Services\Website\Apps;

use App\Models\User;
use App\Models\Website;

/** What one install run knows; the database is filled in once it exists. */
final class AppInstallContext
{
    /** @var array<string, string>|null */
    public ?array $database = null;

    public bool $existingDatabase = false;

    /** @var array<string, mixed> values an installer keeps between its hooks */
    public array $state = [];

    /**
     * @param  array<string, mixed>  $input
     * @param  array{range: array{min_php: string, max_php: string}, label: string, package: mixed}  $plan
     */
    public function __construct(
        public readonly Website $website,
        public readonly array $input,
        public readonly ?User $actor,
        public readonly string $domain,
        public readonly string $rootPath,
        public readonly string $siteOwner,
        public readonly string $phpVersion,
        public readonly array $plan,
        public readonly bool $isPostgresql,
    ) {
    }

    public function input(string $key, string $default = ''): string
    {
        return (string) ($this->input[$key] ?? $default);
    }

    public function siteUrl(): string
    {
        return ((bool) $this->website->enable_ssl ? 'https' : 'http').'://'.$this->domain;
    }

    /** A folder next to the project root, outside what the web server serves. */
    public function siblingPath(string $suffix): string
    {
        return dirname($this->rootPath).'/'.basename($this->rootPath).'-'.$suffix;
    }
}
