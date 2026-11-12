<?php

namespace App\Services\Website\Apps;

use Illuminate\Contracts\Container\Container;

/** The app catalog: one installer class per app key. */
class AppInstallerRegistry
{
    /** @var array<string, class-string<AppInstaller>> */
    public const INSTALLERS = [
        'joomla' => JoomlaInstaller::class,
        'drupal' => DrupalInstaller::class,
        'codeigniter' => CodeIgniterInstaller::class,
        'whmcs' => WhmcsInstaller::class,
        'nextcloud' => NextcloudInstaller::class,
        'prestashop' => PrestashopInstaller::class,
        'opencart' => OpencartInstaller::class,
        'mediawiki' => MediawikiInstaller::class,
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::INSTALLERS);
    }

    public function has(string $key): bool
    {
        return isset(self::INSTALLERS[$key]);
    }

    public function get(string $key): AppInstaller
    {
        if (! $this->has($key)) {
            throw new AppInstallFailed('Unknown application.');
        }

        return $this->container->make(self::INSTALLERS[$key]);
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        return ['key' => $key, ...$this->get($key)->definition()];
    }

    /** @return array<int, string> */
    public function engines(string $key): array
    {
        return $this->get($key)->definition()['engines'] ?? [\App\Models\DatabaseRequest::ENGINE_MARIADB];
    }
}
