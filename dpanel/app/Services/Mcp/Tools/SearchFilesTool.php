<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class SearchFilesTool implements McpTool
{
    /** Never searched for read-only tokens (secrets). */
    private const READ_ONLY_EXCLUDES = ['.env', '.env.*', '*.pem', '*.key', 'id_rsa*', 'id_ed25519*', 'wp-config.php', '.my.cnf', 'debian.cnf', '.bash_history'];

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'search_files';
    }

    public function title(): string
    {
        return 'Search files';
    }

    public function description(): string
    {
        return 'Search a directory tree. With pattern: grep file contents and return "path:line:text" matches (find where a function, setting or error message lives). Without pattern: find files by name glob (e.g. "*.php", "nginx*.conf"). .git and node_modules are skipped; vendor too unless include_vendor is true.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute directory to search.'],
                'pattern' => ['type' => 'string', 'description' => 'Text to look for inside files.'],
                'regex' => ['type' => 'boolean', 'default' => false, 'description' => 'Treat pattern as an extended regular expression.'],
                'case_insensitive' => ['type' => 'boolean', 'default' => false],
                'name' => ['type' => 'string', 'description' => 'File name glob, e.g. "*.php". Required when pattern is empty.'],
                'include_vendor' => ['type' => 'boolean', 'default' => false],
                'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
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
        $pattern = (string) ($arguments['pattern'] ?? '');
        $name = trim((string) ($arguments['name'] ?? ''));
        $max = max(1, min(1000, (int) ($arguments['max_results'] ?? 200)));

        if ($pattern === '' && $name === '') {
            throw new McpToolException('Pass pattern (to search contents) or name (to find files).');
        }
        if (mb_strlen($pattern) > 500 || mb_strlen($name) > 200 || preg_match('/[\x00-\x1F]/', $pattern.$name) === 1) {
            throw new McpToolException('pattern or name is too long or contains control characters.');
        }

        $server = $this->files->server($arguments['server_id'] ?? null);
        $this->files->guardRead($server, $path, $token);

        $excludedDirs = ['.git', 'node_modules', ...(($arguments['include_vendor'] ?? false) ? [] : ['vendor'])];
        $quoted = escapeshellarg($path);

        if ($pattern !== '') {
            $flags = '-rIn --color=never -m 20 '.(($arguments['regex'] ?? false) ? '-E' : '-F').(($arguments['case_insensitive'] ?? false) ? ' -i' : '');
            $options = [
                ...array_map(fn ($dir) => '--exclude-dir='.escapeshellarg($dir), $excludedDirs),
                ...($name !== '' ? ['--include='.escapeshellarg($name)] : []),
                ...($token->allowsMutations() ? [] : array_map(fn ($file) => '--exclude='.escapeshellarg($file), self::READ_ONLY_EXCLUDES)),
            ];
            $command = "grep {$flags} ".implode(' ', $options).' -e '.escapeshellarg($pattern)." -- {$quoted} 2>/dev/null | cut -c1-400 | head -n ".($max + 1);
        } else {
            $prune = implode(' -o ', array_map(fn ($dir) => '-name '.escapeshellarg($dir), $excludedDirs));
            $command = "find {$quoted} \\( {$prune} \\) -prune -o -name ".escapeshellarg($name)." -printf '%p\\t%y\\t%s\\t%TY-%Tm-%Td %TH:%TM\\n' 2>/dev/null | head -n ".($max + 1);
        }

        $output = $this->files->run($server, "test -d {$quoted} || { echo 'Not a directory:' {$quoted}; exit 2; }\n".$command, $token, outputLimit: 500000);
        $matches = preg_split('/\n/', trim($output), -1, PREG_SPLIT_NO_EMPTY);

        return [
            'path' => $path,
            'mode' => $pattern !== '' ? 'content' : 'name',
            'format' => $pattern !== '' ? 'path:line:text' : "path\ttype\tsize\tmodified",
            'matches' => array_slice($matches, 0, $max),
            'count' => min(count($matches), $max),
            'truncated' => count($matches) > $max,
        ];
    }
}
