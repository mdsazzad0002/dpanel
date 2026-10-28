<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Services\ServerPanel\CommandSafetyService;

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
     * Read-only diagnostics allowed over MCP on top of serverpanel.safe_patterns.
     * Kept here rather than in that config, which also auto-approves commands
     * in the panel's own command runner.
     */
    private const READ_ONLY_EXTRA = [
        'ps aux', 'ps -e', 'ps -o', 'ps -p', 'top -b', 'vmstat', 'nproc', 'lscpu', 'du -sh', 'du -h', 'df -i',
        'systemctl show', 'systemctl is-active', 'systemctl is-enabled', 'systemctl is-failed', 'systemctl list-units', 'systemctl list-timers', 'systemctl --failed',
    ];

    /** Paths a read-only token must never read (secrets, keys). */
    public const SENSITIVE_PATHS = ['/etc/shadow', '/etc/gshadow', '/etc/sudoers', '/.ssh', 'id_rsa', 'id_ed25519', 'id_ecdsa', '.env', '/etc/drust', '/etc/letsencrypt', 'privkey', '.pem', '.key', 'wp-config.php', 'debian.cnf', '.my.cnf', '.bash_history', '/proc/'];

    public function __construct(private readonly CommandSafetyService $safety) {}

    /**
     * @return array{allowed: bool, risk_level: string, reason: string}
     */
    public function evaluate(string $command, McpAccessToken $token): array
    {
        $classification = $this->safety->classify($command);
        $normalized = $classification['normalized_command'];

        if ($classification['risk_level'] === 'blocked') {
            return $this->deny('blocked', $classification['risk_reason']);
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

        foreach (self::SENSITIVE_PATHS as $path) {
            if (str_contains($normalized, $path)) {
                return $this->deny('approval_required', "Read-only MCP tokens cannot read sensitive paths ({$path}).");
            }
        }

        $segments = array_map('trim', explode('|', $normalized));
        $first = array_shift($segments);

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
     * @return array<int, string>
     */
    public function readOnlyCommands(): array
    {
        return array_values(array_unique([...(array) config('serverpanel.safe_patterns', []), ...self::READ_ONLY_EXTRA]));
    }

    private function isReadOnlyCommand(string $normalized): bool
    {
        if ($this->safety->classify($normalized)['risk_level'] === 'safe') {
            return true;
        }

        foreach ((array) config('serverpanel.approval_required_patterns', []) as $pattern) {
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
