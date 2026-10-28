<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class ReadFileTool implements McpTool
{
    /** Bytes of content returned per call. */
    private const MAX_BYTES = 200000;

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function title(): string
    {
        return 'Read file';
    }

    public function description(): string
    {
        return 'Read a text file on a server (source code, config, .htaccess, php.ini …), optionally a range of lines. Returns the content, the total line count and a sha256 you can pass to write_file as expected_sha256. Binary files are reported but not returned. Read-only tokens cannot read secrets (.env, keys, wp-config.php …).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute file path.'],
                'offset' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'First line to return (1-based).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000, 'description' => 'Number of lines to return.'],
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
        $offset = max(1, (int) ($arguments['offset'] ?? 1));
        $limit = max(1, min(5000, (int) ($arguments['limit'] ?? 1000)));
        $last = $offset + $limit - 1;
        $server = $this->files->server($arguments['server_id'] ?? null);
        $this->files->guardRead($server, $path, $token);

        $quoted = escapeshellarg($path);
        $max = self::MAX_BYTES;

        $output = $this->files->run($server, implode("\n", [
            "test -f {$quoted} || { echo 'Not a regular file:' {$quoted}; exit 2; }",
            "test -r {$quoted} || { echo 'Permission denied:' {$quoted}; exit 2; }",
            "echo \"\$(stat -c %s -- {$quoted}) \$(wc -l < {$quoted}) \$(sha256sum -- {$quoted} | cut -d' ' -f1)\"",
            "if [ \"\$(head -c 8000 -- {$quoted} | wc -c)\" = \"\$(head -c 8000 -- {$quoted} | tr -d '\\000' | wc -c)\" ]; then",
            '  echo text',
            "  sed -n '{$offset},{$last}p' -- {$quoted} | head -c {$max} | base64 -w0",
            'else',
            '  echo binary',
            'fi',
        ]), $token, outputLimit: (int) ($max * 1.4) + 1024);

        $lines = preg_split('/\n/', trim($output));
        if (count($lines) < 2 || preg_match('/^(\d+) (\d+) ([0-9a-f]{64})$/', trim($lines[0]), $stat) !== 1) {
            throw new McpToolException('Could not read '.$path.': unexpected output.');
        }

        $result = [
            'path' => $path,
            'size' => (int) $stat[1],
            'total_lines' => (int) $stat[2],
            'sha256' => $stat[3],
        ];

        if (trim($lines[1]) === 'binary') {
            return [...$result, 'binary' => true, 'content' => null];
        }

        $content = base64_decode(trim($lines[2] ?? ''), true);
        if ($content === false) {
            throw new McpToolException('Could not read '.$path.': unexpected output.');
        }

        $returned = substr_count($content, "\n") + (str_ends_with($content, "\n") || $content === '' ? 0 : 1);

        return [
            ...$result,
            'binary' => false,
            'from_line' => $offset,
            'to_line' => $offset + max(0, $returned - 1),
            'more' => strlen($content) >= $max || $offset + $returned - 1 < $result['total_lines'],
            'content' => mb_convert_encoding($content, 'UTF-8', 'UTF-8'),
        ];
    }
}
