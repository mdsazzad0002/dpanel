<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;

class AnalyzeDiskUsageTool implements McpTool
{
    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'analyze_disk_usage';
    }

    public function title(): string
    {
        return 'Analyze disk usage';
    }

    public function description(): string
    {
        return 'Find what fills a disk: usage and inodes of every mounted filesystem, the biggest directories directly under a path, and the largest files below it (over 50 MB). Drill down by calling it again on the biggest directory. Common culprits: logs in /var/log or a site\'s storage/logs, old backups, mail spools, journal, Docker images.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'default' => '/', 'description' => 'Absolute directory to analyze.'],
                'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15, 'description' => 'How many directories and files to list.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $path = $this->files->path($arguments['path'] ?? '/');
        $top = max(1, min(50, (int) ($arguments['top'] ?? 15)));
        $server = $this->files->server($arguments['server_id'] ?? null);
        $this->files->guardRead($server, $path, $token);
        $p = escapeshellarg($path);

        $raw = $this->files->run($server, implode("\n", [
            "echo @@filesystems; df -hP -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | tail -n +2 | awk '{print \$6\"|\"\$2\"|\"\$3\"|\"\$4\"|\"\$5}'",
            "echo @@inodes; df -iP -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | tail -n +2 | awk '{print \$6\"|\"\$5}'",
            "echo @@directories; timeout 90 du -xk --max-depth=1 -- {$p} 2>/dev/null | sort -rn | head -n ".($top + 1),
            "echo @@files; timeout 60 find {$p} -xdev -type f -size +50M -printf '%s|%TY-%Tm-%Td|%p\\n' 2>/dev/null | sort -t'|' -k1 -rn | head -n {$top}",
            'true',
        ]), $token, outputLimit: 40000);

        $section = null;
        $data = ['filesystems' => [], 'inodes' => [], 'directories' => [], 'files' => []];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^@@(\w+)$/', $line, $m) === 1) {
                $section = $m[1];

                continue;
            }
            if ($section === null || trim($line) === '') {
                continue;
            }

            $data[$section][] = match ($section) {
                'filesystems' => array_combine(['mount', 'size', 'used', 'available', 'used_percent'], array_pad(explode('|', $line, 5), 5, null)),
                'inodes' => array_combine(['mount', 'inodes_used_percent'], array_pad(explode('|', $line, 2), 2, null)),
                'directories' => preg_match('/^(\d+)\s+(.+)$/', $line, $d) === 1 ? ['path' => $d[2], 'size' => $this->human((int) $d[1] * 1024)] : ['raw' => $line],
                'files' => (fn (array $f) => ['path' => $f[2] ?? $line, 'size' => $this->human((int) $f[0]), 'modified' => $f[1] ?? null])(explode('|', $line, 3)),
            };
        }

        $warnings = [];
        foreach ($data['filesystems'] as $fs) {
            if ((int) $fs['used_percent'] >= 90) {
                $warnings[] = "{$fs['mount']} is {$fs['used_percent']} full.";
            }
        }
        foreach ($data['inodes'] as $fs) {
            if ((int) $fs['inodes_used_percent'] >= 90) {
                $warnings[] = "{$fs['mount']} has used {$fs['inodes_used_percent']} of its inodes (many small files: sessions, cache, mail).";
            }
        }

        return [
            'path' => $path,
            ...$data,
            'warnings' => $warnings,
            'hint' => 'The first directory line is the total for the path itself. Clean up only with the user\'s agreement; journalctl --vacuum-size and rotating logs are safer than deleting files.',
        ];
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return round($bytes / 1024 ** $i, 1).' '.$unit;
            }
        }

        return "{$bytes} B";
    }
}
