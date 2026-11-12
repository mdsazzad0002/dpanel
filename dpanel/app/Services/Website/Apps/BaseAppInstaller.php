<?php

namespace App\Services\Website\Apps;

/** Defaults for the optional hooks. */
abstract class BaseAppInstaller implements AppInstaller
{
    /** Validation for the shared account fields. */
    protected const SITE_NAME = ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u'];

    protected const ADMIN_EMAIL = ['required', 'email', 'max:190'];

    protected const ADMIN_PASSWORD = ['required', 'string', 'min:12', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u'];

    public function __construct(protected readonly AppInstallSupport $support)
    {
    }

    public function rules(): array
    {
        return [];
    }

    public function postgresModule(): string
    {
        return 'pdo_pgsql';
    }

    public function preflight(AppInstallContext $context): void
    {
    }

    public function afterDatabase(AppInstallContext $context): void
    {
    }

    public function complete(AppInstallContext $context): string
    {
        return '';
    }

    /** Run a drust step and fail with "$what failed: …" when it does. */
    protected function run(AppInstallContext $context, string $action, array $extra, string $what, int $timeout = 660): string
    {
        $step = $this->support->step($context, $action, $extra, $timeout);
        if (! $step['success']) {
            throw new AppInstallFailed("{$what} failed: ".$step['output']);
        }

        return $step['output'];
    }

    /**
     * Newest version per series from a range table; unknown newer series use
     * the last entry's range.
     *
     * @param  array<string, array{min_php: string, max_php: string}>  $table
     * @return array{min_php: string, max_php: string}
     */
    protected static function rangeFor(array $table, string $series): array
    {
        return $table[$series] ?? end($table);
    }

    /**
     * Newest release per series key, newest series first.
     *
     * @param  array<int, array<string, mixed>>  $candidates  each with version and series
     * @return array<int, array<string, mixed>>
     */
    protected static function newestPerSeries(array $candidates): array
    {
        $bySeries = [];
        foreach ($candidates as $candidate) {
            $series = $candidate['series'];
            if (! isset($bySeries[$series]) || version_compare($candidate['version'], $bySeries[$series]['version'], '>')) {
                $bySeries[$series] = $candidate;
            }
        }
        uksort($bySeries, static fn ($a, $b): int => version_compare((string) $b, (string) $a));

        return array_values($bySeries);
    }

    protected function removeIfPresent(AppInstallContext $context, string $relative): void
    {
        if ($this->support->filemanager->directoryExists($context->rootPath.'/'.$relative)) {
            $this->support->filemanager->deletePath($context->siteOwner, $context->rootPath.'/'.$relative);
        }
    }
}
