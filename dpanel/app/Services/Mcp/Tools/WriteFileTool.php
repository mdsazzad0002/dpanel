<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class WriteFileTool implements McpTool
{
    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function title(): string
    {
        return 'Write file';
    }

    public function description(): string
    {
        return 'Create a file or replace its whole content (up to 1 MiB). The write is atomic, keeps the existing owner and mode (new files take the directory\'s owner), and backs up the old file under '.McpFileAccess::backupRoot().'/files first; the backup path is returned so the change can be undone. Pass expected_sha256 from read_file to refuse the write if the file changed in the meantime. For small changes prefer edit_file. Needs a full-access token.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute file path.'],
                'content' => ['type' => 'string', 'description' => 'The complete new file content.'],
                'expected_sha256' => ['type' => 'string', 'description' => 'sha256 from read_file; the write is refused if the file no longer matches.'],
                'create_dirs' => ['type' => 'boolean', 'default' => false, 'description' => 'Create missing parent directories.'],
                'backup' => ['type' => 'boolean', 'default' => true],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $path = $this->files->path($arguments['path'] ?? null);
        if (! is_string($arguments['content'] ?? null)) {
            throw new McpToolException('content must be a string.');
        }

        $expected = $arguments['expected_sha256'] ?? null;
        if ($expected !== null && (! is_string($expected) || preg_match('/^[0-9a-f]{64}$/', $expected = strtolower($expected)) !== 1)) {
            throw new McpToolException('expected_sha256 must be a 64-character hex sha256.');
        }

        $server = $this->files->server($arguments['server_id'] ?? null);
        $target = $this->files->guardWrite($server, $path, $token);

        return $this->files->write(
            $server,
            $target,
            $arguments['content'],
            $token,
            $expected,
            (bool) ($arguments['backup'] ?? true),
            (bool) ($arguments['create_dirs'] ?? false),
        );
    }
}
