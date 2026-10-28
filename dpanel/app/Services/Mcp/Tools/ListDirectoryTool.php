<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;

class ListDirectoryTool implements McpTool
{
    private const MAX_ENTRIES = 2000;

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'list_directory';
    }

    public function title(): string
    {
        return 'List directory';
    }

    public function description(): string
    {
        return 'Browse a directory on a server: every entry with its type (f file, d directory, l symlink), mode, owner, size and modification time. Use depth > 1 to see a tree. Start from a website\'s root_path (get_website) to explore its files.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute directory path.'],
                'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1],
                'show_hidden' => ['type' => 'boolean', 'default' => true, 'description' => 'Include dotfiles such as .htaccess and .env.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['path'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $path = $this->files->path($arguments['path'] ?? null);
        $depth = max(1, min(5, (int) ($arguments['depth'] ?? 1)));
        $showHidden = (bool) ($arguments['show_hidden'] ?? true);
        $server = $this->files->server($arguments['server_id'] ?? null);
        $this->files->guardRead($server, $path, $token);

        $quoted = escapeshellarg($path);
        $format = escapeshellarg('%p\t%y\t%m\t%u:%g\t%s\t%TY-%Tm-%Td %TH:%TM\t%l\n');
        $limit = self::MAX_ENTRIES + 1;

        $output = $this->files->run($server, implode("\n", [
            "test -d {$quoted} || { echo 'Not a directory:' {$quoted}; exit 2; }",
            "find {$quoted} -mindepth 1 -maxdepth {$depth} ".($showHidden ? '' : "-name '.*' -prune -o ")."-printf {$format} 2>/dev/null | LC_ALL=C sort | head -n {$limit}",
        ]), $token, outputLimit: 400000);

        $entries = [];
        foreach (preg_split('/\n/', trim($output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 6) {
                continue;
            }

            $entries[] = array_filter([
                'path' => $fields[0],
                'type' => $fields[1],
                'mode' => $fields[2],
                'owner' => $fields[3],
                'size' => (int) $fields[4],
                'modified' => $fields[5],
                'link_target' => ($fields[6] ?? '') !== '' ? $fields[6] : null,
            ], fn ($value) => $value !== null);
        }

        $truncated = count($entries) > self::MAX_ENTRIES;

        return [
            'path' => $path,
            'entries' => array_slice($entries, 0, self::MAX_ENTRIES),
            'count' => min(count($entries), self::MAX_ENTRIES),
            'truncated' => $truncated,
        ];
    }
}
