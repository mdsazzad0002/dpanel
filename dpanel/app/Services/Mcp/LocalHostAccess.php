<?php

namespace App\Services\Mcp;

use App\Support\Platform\LocalCommand;

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

    public function __construct(private readonly ?LocalCommand $local = null) {}

    public function level(): string
    {
        $path = $this->configPath();

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

    /** The opt-in file, in the OS's dPanel config folder (Spec §4 config_dir). */
    public function configPath(): string
    {
        $configured = config('mcp.local_config_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($this->local()->os()) {
            'windows' => rtrim((string) (getenv('ProgramData') ?: 'C:\\ProgramData'), '\\/').'\\dPanel\\config\\mcp-local.conf',
            'macos' => '/Library/dPanel/config/mcp-local.conf',
            default => '/etc/dpanel/mcp-local.conf',
        };
    }

    /**
     * How an admin opts in, for error messages. $levels is "[read|full]" or "full".
     */
    public function enableHint(string $levels = '[read|full]'): string
    {
        if (! $this->local()->isWindows()) {
            return "sudo dpanel mcp local on {$levels}";
        }

        return 'the line MCP_LOCAL_EXEC='.($levels === 'full' ? 'full' : 'read (or full)').' in '.$this->configPath().' (writable by Administrators only)';
    }

    private function local(): LocalCommand
    {
        return $this->local ?? app(LocalCommand::class);
    }
}
