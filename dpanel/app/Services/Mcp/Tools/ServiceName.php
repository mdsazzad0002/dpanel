<?php

namespace App\Services\Mcp\Tools;

use App\Services\Mcp\McpToolException;

/**
 * Validates a systemd unit name before it is put in a command line.
 */
final class ServiceName
{
    public static function validate(mixed $value): string
    {
        $unit = trim((string) $value);

        if ($unit === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9@._:-]{0,99}$/', $unit) !== 1) {
            throw new McpToolException('service must be a systemd unit name such as mariadb or php8.3-fpm.');
        }

        return $unit;
    }
}
