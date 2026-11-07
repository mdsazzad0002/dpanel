<?php

namespace App\Support\Platform;

/**
 * The desktop profile has no Redis (plans/windows-macos-native-plan.md).
 *
 * Read env() here only from config files: a cached config never calls them.
 */
class LocalProfile
{
    /** Long enough that a database queue does not retry a job still running. */
    public const QUEUE_RETRY_AFTER = 3700;

    public static function isActive(): bool
    {
        return strtolower(trim((string) env('DPANEL_PROFILE'))) === 'local';
    }

    /** The configured queue or cache backend, with Redis moved to the database. */
    public static function backend(string $configured): string
    {
        return self::isActive() && $configured === 'redis' ? 'database' : $configured;
    }
}
