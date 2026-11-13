<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Services\ServerPanel\CommandSafetyService;
use App\Support\Platform\LocalCommand;

/**
 * Decides whether a shell command may run over MCP. Builds on the panel's
 * CommandSafetyService, but is stricter for read-only tokens: its "safe"
 * check only matches a command's prefix, so `ls; curl … -o x` would pass it.
 */
class McpCommandPolicy
{
    /** Commands a read-only command may pipe its output into. */
    private const PIPE_FILTERS = ['grep', 'egrep', 'fgrep', 'head', 'tail', 'wc', 'sort', 'uniq', 'cut', 'column'];

    /**
     * Read-only diagnostics allowed over MCP on top of dpanel.safe_patterns.
     * Kept here rather than in that config, which also auto-approves commands
     * in the panel's own command runner.
     */
    private const READ_ONLY_EXTRA = [
        'ps aux', 'ps -e', 'ps -o', 'ps -p', 'top -b', 'vmstat', 'nproc', 'lscpu', 'du -sh', 'du -h', 'df -i',
        'systemctl show', 'systemctl is-active', 'systemctl is-enabled', 'systemctl is-failed', 'systemctl list-units', 'systemctl list-timers', 'systemctl --failed',
    ];

    /** Paths a read-only token must never read (secrets, keys). */
    public const SENSITIVE_PATHS = ['/etc/shadow', '/etc/gshadow', '/etc/sudoers', '/.ssh', 'id_rsa', 'id_ed25519', 'id_ecdsa', '.env', '/etc/drust', '/etc/letsencrypt', 'privkey', '.pem', '.key', 'wp-config.php', 'debian.cnf', '.my.cnf', '.bash_history', '/proc/'];

    /**
     * More secrets on a Windows panel host, compared lower case with forward
     * slashes: drust's config folder (token, drust.env), the registry hives
     * and saved Windows credentials.
     */
    public const WINDOWS_SENSITIVE_PATHS = ['/dpanel/config', 'drust.env', '/system32/config', 'ntds.dit', '/microsoft/credentials', '/microsoft/protect'];

    /**
     * Read-only commands that need tools only some panel hosts have, keyed by
     * LocalCommand::supports(). Git's bash on Windows has coreutils, grep, find
     * and a basic ps, but none of these.
     */
    private const HOST_TOOLS = [
        LocalCommand::SYSTEMD => ['systemctl', 'journalctl', 'hostnamectl'],
        LocalCommand::LINUX_TOOLS => ['lsb_release', 'free', 'vmstat', 'nproc', 'lscpu', 'top -b', 'cat /etc/', 'cat /var/log'],
        LocalCommand::UNIX_TOOLS => ['uptime', 'ps aux', 'ps -o'],
    ];

    /**
     * Commands a Windows panel host refuses for every token, as the Linux list
     * in dpanel.blocked_commands does (rm -rf /, mkfs, reboot, passwd …).
     * Matched lower case with forward slashes. A drive root is "c:", "c:/" or
     * "c:/*"; bash's "/c" is already caught by "rm -rf /".
     */
    private const WINDOWS_BLOCKED = [
        '/\bformat(?:\.com)?\s+["\']?[a-z]:/',
        '/\b(?:rm|rd|rmdir|del|erase|remove-item|ri)\b[^;&|]*\s["\']?[a-z]:\/?\*?["\']?(?=\s|$|;|&|\|)/',
        '/\bicacls\s+["\']?[a-z]:\/?["\']?\s/',
        '/\b(?:diskpart|bcdedit|bootrec|vssadmin|wbadmin|clear-disk|initialize-disk|format-volume|remove-partition|restart-computer|stop-computer)\b/',
        '/\bcipher(?:\.exe)?\s+\/w/',
        '/\bnet(?:\.exe)?\s+(?:user|localgroup)\b/',
        '/\breg(?:\.exe)?\s+(?:delete|import|restore|load)\b/',
        '/\bnetsh\b.*\bstate\s+off\b/',
        '/\bset-netfirewallprofile\b.*-enabled\s+\$?false\b/',
    ];

    public function __construct(private readonly CommandSafetyService $safety) {}

    /**
     * @param  bool  $panelHost  The command runs on the panel host (mcp-exec.sh),
     *                           not on a managed server over SSH. Only then
     *                           does the panel host's OS matter.
     * @return array{allowed: bool, risk_level: string, reason: string}
     */
    public function evaluate(string $command, McpAccessToken $token, bool $panelHost = false): array
    {
        $classification = $this->safety->classify($command);
        $normalized = $classification['normalized_command'];

        if ($classification['risk_level'] === 'blocked') {
            return $this->deny('blocked', $classification['risk_reason']);
        }

        $windows = $panelHost && $this->host()->isWindows();
        if ($windows && ($reason = $this->windowsBlockReason($command)) !== null) {
            return $this->deny('blocked', $reason);
        }

        if ($token->allowsMutations()) {
            return [
                'allowed' => true,
                'risk_level' => $classification['risk_level'],
                'reason' => $classification['risk_level'] === 'safe'
                    ? $classification['risk_reason']
                    : 'Approved by a full-access MCP token.',
            ];
        }

        // Check the raw command: normalize() folds newlines into spaces, but
        // the shell would still run each line as its own command.
        if (preg_match('/[;&`<>\n\r\x00]|\$\(|\$\{|\|\|/', $command) === 1) {
            return $this->deny('approval_required', 'Read-only MCP tokens cannot chain commands, redirect output or use substitution (; & && || ` $( > <).');
        }

        // Windows reads "\" as "/" (normalize() has already lowered the case).
        $comparable = $windows ? str_replace('\\', '/', $normalized) : $normalized;
        foreach ($windows ? [...self::SENSITIVE_PATHS, ...self::WINDOWS_SENSITIVE_PATHS] : self::SENSITIVE_PATHS as $path) {
            if (str_contains($comparable, $path)) {
                return $this->deny('approval_required', "Read-only MCP tokens cannot read sensitive paths ({$path}).");
            }
        }

        $segments = array_map('trim', explode('|', $normalized));
        $first = array_shift($segments);

        if ($panelHost && ($missing = $this->missingHostTool((string) $first)) !== null) {
            return $this->deny('approval_required', "\"{$missing}\" is not available on this panel host ({$this->host()->os()}). Run it on a managed Linux server instead (server_id).");
        }

        if (! $this->isReadOnlyCommand((string) $first)) {
            return $this->deny('approval_required', 'Not a known read-only command. Use a full-access MCP token, or one of the dedicated diagnostic tools.');
        }

        foreach ($segments as $segment) {
            $binary = strtok($segment, ' ');
            if (! in_array($binary, self::PIPE_FILTERS, true)) {
                return $this->deny('approval_required', 'Read-only MCP tokens may only pipe into: '.implode(', ', self::PIPE_FILTERS).'.');
            }
        }

        return ['allowed' => true, 'risk_level' => 'safe', 'reason' => $classification['risk_reason']];
    }

    /**
     * Command prefixes a read-only token may run, for telling the model up
     * front instead of letting it guess and be refused.
     *
     * @param  bool  $panelHost  Leave out what the panel host cannot run.
     * @return array<int, string>
     */
    public function readOnlyCommands(bool $panelHost = false): array
    {
        $commands = array_values(array_unique([...(array) config('dpanel.safe_patterns', []), ...self::READ_ONLY_EXTRA]));

        return $panelHost
            ? array_values(array_filter($commands, fn (string $command) => $this->missingHostTool(mb_strtolower($command)) === null))
            : $commands;
    }

    /** Why a Windows panel host refuses $command for every token, or null. */
    public function windowsBlockReason(string $command): ?string
    {
        $comparable = str_replace('\\', '/', $this->safety->normalize($command));

        foreach (self::WINDOWS_BLOCKED as $pattern) {
            if (preg_match($pattern, $comparable) === 1) {
                return 'Command matches a blocked dangerous Windows pattern (drive root, disk, boot, accounts, registry or firewall).';
            }
        }

        return null;
    }

    /** The read-only prefix $normalized starts with that the panel host lacks, or null. */
    private function missingHostTool(string $normalized): ?string
    {
        foreach (self::HOST_TOOLS as $tools => $prefixes) {
            if ($this->host()->supports($tools)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, str_ends_with($prefix, '/') ? $prefix : $prefix.' ')) {
                    return $prefix;
                }
            }
        }

        return null;
    }

    private function host(): LocalCommand
    {
        return app(LocalCommand::class);
    }

    private function isReadOnlyCommand(string $normalized): bool
    {
        if ($this->safety->classify($normalized)['risk_level'] === 'safe') {
            return true;
        }

        foreach ((array) config('dpanel.approval_required_patterns', []) as $pattern) {
            if ($pattern !== '' && str_contains($normalized, mb_strtolower((string) $pattern))) {
                return false;
            }
        }

        foreach (self::READ_ONLY_EXTRA as $prefix) {
            // Whole words only, so "id" does not let "idle-script" through.
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{allowed: bool, risk_level: string, reason: string}
     */
    private function deny(string $riskLevel, string $reason): array
    {
        return ['allowed' => false, 'risk_level' => $riskLevel, 'reason' => $reason];
    }
}
