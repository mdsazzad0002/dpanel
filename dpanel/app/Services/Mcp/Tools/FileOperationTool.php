<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class FileOperationTool implements McpTool
{
    private const ACTIONS = ['mkdir', 'move', 'copy', 'delete', 'chmod', 'chown'];

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'file_operation';
    }

    public function title(): string
    {
        return 'File operation';
    }

    public function description(): string
    {
        return 'Manage files and directories: mkdir, move (rename), copy, delete, chmod, chown. delete does not erase anything: it moves the path into '.McpFileAccess::backupRoot().'/trash and returns where, so it can be restored with move. move and copy refuse to overwrite an existing destination unless overwrite is true. Needs a full-access token.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'path' => ['type' => 'string', 'description' => 'Absolute path to act on (the source for move/copy).'],
                'destination' => ['type' => 'string', 'description' => 'Absolute destination path for move/copy.'],
                'mode' => ['type' => 'string', 'description' => 'Octal mode for chmod, e.g. 644 or 0755.'],
                'owner' => ['type' => 'string', 'description' => 'user or user:group for chown.'],
                'recursive' => ['type' => 'boolean', 'default' => false, 'description' => 'Needed to delete a directory; applies chmod/chown to a whole tree.'],
                'overwrite' => ['type' => 'boolean', 'default' => false],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, self::ACTIONS, true)) {
            throw new McpToolException('action must be one of: '.implode(', ', self::ACTIONS).'.');
        }

        $path = $this->files->path($arguments['path'] ?? null);
        $recursive = (bool) ($arguments['recursive'] ?? false);
        $server = $this->files->server($arguments['server_id'] ?? null);

        // A copy only reads its source (its destination is checked below);
        // everything else changes the path itself.
        if ($action === 'copy') {
            $this->files->guardRead($server, $path, $token);
        } else {
            $this->files->guardWrite($server, $path, $token);
        }

        if (in_array($action, ['move', 'delete'], true) || $recursive) {
            $this->files->guardTree($path);
        }

        $quoted = escapeshellarg($path);
        $flag = $recursive ? '-R ' : '';

        $result = match ($action) {
            'delete' => ['trashed_to' => $this->files->trash($server, $path, $recursive, $token)],
            'mkdir' => $this->runIt($server, "mkdir -p -- {$quoted}", $token),
            'chmod' => $this->runIt($server, "chmod {$flag}".$this->mode($arguments['mode'] ?? null)." -- {$quoted}", $token),
            'chown' => $this->runIt($server, "chown {$flag}".$this->owner($arguments['owner'] ?? null)." -- {$quoted}", $token),
            'move', 'copy' => $this->transfer($action, $server, $path, $arguments, $token),
        };

        return ['action' => $action, 'path' => $path, ...$result];
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(string $action, ?Server $server, string $path, array $arguments, McpAccessToken $token): array
    {
        $destination = $this->files->path($arguments['destination'] ?? null, 'destination');
        $this->files->guardWrite($server, $destination, $token);

        $source = escapeshellarg($path);
        $target = escapeshellarg($destination);
        $overwrite = (bool) ($arguments['overwrite'] ?? false);

        $this->files->run($server, implode("\n", array_filter([
            "{ [ -e {$source} ] || [ -L {$source} ]; } || { echo 'No such file or directory:' {$source}; exit 2; }",
            $overwrite ? null : "if [ -e {$target} ] || [ -L {$target} ]; then echo {$target} 'already exists; pass overwrite=true to replace it.'; exit 2; fi",
            $action === 'move' ? "mv -fT -- {$source} {$target}" : "cp -aT -- {$source} {$target}",
        ])), $token);

        return ['destination' => $destination];
    }

    /**
     * @return array<string, mixed>
     */
    private function runIt(?Server $server, string $command, McpAccessToken $token): array
    {
        $this->files->run($server, $command, $token);

        return ['done' => true];
    }

    private function mode(mixed $value): string
    {
        $mode = trim((string) $value);
        if (preg_match('/^[0-7]{3,4}$/', $mode) !== 1) {
            throw new McpToolException('mode must be octal, e.g. 644 or 0755.');
        }

        return $mode;
    }

    private function owner(mixed $value): string
    {
        $owner = trim((string) $value);
        if (preg_match('/^[a-z_][a-z0-9_.-]{0,31}(:[a-z_][a-z0-9_.-]{0,31})?$/i', $owner) !== 1) {
            throw new McpToolException('owner must be user or user:group.');
        }

        return $owner;
    }
}
