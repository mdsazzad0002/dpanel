<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\Mcp\McpToolException;

class ReadServiceLogsTool implements McpTool
{
    public function __construct(private readonly McpCommandExecutor $executor) {}

    public function name(): string
    {
        return 'read_service_logs';
    }

    public function title(): string
    {
        return 'Read service logs';
    }

    public function description(): string
    {
        return 'Recent journald logs for a systemd unit (journalctl -u). Narrow with since (e.g. "1 hour ago", "2026-10-27 10:00") and grep for a case-insensitive pattern.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'description' => 'systemd unit name.'],
                'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
                'since' => ['type' => 'string', 'description' => 'journalctl --since value.'],
                'grep' => ['type' => 'string', 'description' => 'Only lines matching this pattern.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['service'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $unit = ServiceName::validate($arguments['service'] ?? null);
        $lines = max(1, min(1000, (int) ($arguments['lines'] ?? 200)));
        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);

        $command = 'journalctl -u '.escapeshellarg($unit).' -n '.$lines.' --no-pager -o short-iso';

        $since = trim((string) ($arguments['since'] ?? ''));
        if ($since !== '') {
            if (preg_match('/^[A-Za-z0-9 :.\-+]{1,40}$/', $since) !== 1) {
                throw new McpToolException('since may only contain letters, digits, spaces and : . - +');
            }
            $command .= ' --since '.escapeshellarg($since);
        }

        $grep = (string) ($arguments['grep'] ?? '');
        if ($grep !== '') {
            $command .= ' --grep '.escapeshellarg(mb_substr($grep, 0, 200)).' --case-sensitive=false';
        }

        return $this->executor->run($server, $command.' 2>&1', $token, trusted: true);
    }
}
