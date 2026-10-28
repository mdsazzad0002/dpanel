<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class EditFileTool implements McpTool
{
    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'edit_file';
    }

    public function title(): string
    {
        return 'Edit file';
    }

    public function description(): string
    {
        return 'Replace an exact piece of text in a file (config line, buggy code, typo). old_string must match the file exactly, including whitespace, and be unique unless replace_all is true; include surrounding lines to make it unique. The old file is backed up and the write is atomic, as with write_file. Needs a full-access token.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute file path.'],
                'old_string' => ['type' => 'string', 'description' => 'Exact text to replace.'],
                'new_string' => ['type' => 'string', 'description' => 'Replacement text.'],
                'replace_all' => ['type' => 'boolean', 'default' => false],
                'backup' => ['type' => 'boolean', 'default' => true],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $path = $this->files->path($arguments['path'] ?? null);
        $old = $arguments['old_string'] ?? null;
        $new = $arguments['new_string'] ?? null;

        if (! is_string($old) || $old === '' || ! is_string($new)) {
            throw new McpToolException('old_string must be a non-empty string and new_string a string.');
        }
        if ($old === $new) {
            throw new McpToolException('old_string and new_string are the same.');
        }

        $server = $this->files->server($arguments['server_id'] ?? null);
        $target = $this->files->guardWrite($server, $path, $token);
        $content = $this->files->readWhole($server, $target, $token);

        $count = substr_count($content, $old);
        if ($count === 0) {
            throw new McpToolException('old_string was not found in '.$path.'. Read the file again and copy the text exactly, including indentation.');
        }
        if ($count > 1 && ! ($arguments['replace_all'] ?? false)) {
            throw new McpToolException("old_string occurs {$count} times in {$path}. Add surrounding lines to make it unique, or pass replace_all=true.");
        }

        $firstLine = substr_count(substr($content, 0, (int) strpos($content, $old)), "\n") + 1;

        $result = $this->files->write(
            $server,
            $target,
            str_replace($old, $new, $content),
            $token,
            hash('sha256', $content),
            (bool) ($arguments['backup'] ?? true),
        );

        return [...$result, 'replacements' => $count, 'first_line' => $firstLine];
    }
}
