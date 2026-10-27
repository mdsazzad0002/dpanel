<?php

namespace App\Services\Mcp;

/**
 * Reads whether the panel host lets MCP clients run commands on it. Root
 * sets this with `sudo dpanel mcp local on [read|full]`; the panel can only
 * read it, so a compromised panel cannot raise its own access.
 */
class LocalHostAccess
{
    public const OFF = 'off';

    public const READ = 'read';

    public const FULL = 'full';

    /** The file exists but the panel can't read it; mcp-exec.sh decides. */
    public const UNKNOWN = 'unknown';

    public function level(): string
    {
        $path = (string) config('mcp.local_config_path', '/etc/dpanel/mcp-local.conf');

        if (! @is_file($path)) {
            return self::OFF;
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            return self::UNKNOWN;
        }

        if (preg_match('/^MCP_LOCAL_EXEC=(read|full)\s*$/m', $contents, $match) !== 1) {
            return self::OFF;
        }

        return $match[1];
    }
}
