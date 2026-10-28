<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

class FindWebsiteErrorsTool implements McpTool
{
    private const ERROR_PATTERN = 'error|exception|fatal|critical|emerg|alert|traceback|uncaught|denied|refused|failed|timed out|segfault';

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'find_website_errors';
    }

    public function title(): string
    {
        return 'Find website errors';
    }

    public function description(): string
    {
        return 'First step when a website is broken: requests its home page and reports the HTTP status, then finds the log files the site wrote recently (Laravel storage/logs, error_log, WordPress debug.log, app *.log, the PHP-FPM log) and returns their latest error lines. Follow up with read_file / search_files on the files it names, then edit_file to fix.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'The website domain, e.g. example.com.'],
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 7, 'description' => 'Only logs changed within this many days.'],
                'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 40, 'description' => 'Error lines per log file.'],
                'include_warnings' => ['type' => 'boolean', 'default' => false],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['domain'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        if ($domain === '') {
            throw new McpToolException('domain is required.');
        }

        $site = Website::query()
            ->visibleTo($token->user)
            ->whereIn('domain', [$domain, preg_replace('/^www\./', '', $domain)])
            ->first();

        if (! $site) {
            throw new McpToolException("No website found for {$domain}. Call list_websites to search.");
        }

        $directories = collect([$site->project_root, $site->root_path])
            ->filter(fn ($path) => is_string($path) && str_starts_with($path, '/'))
            ->map(fn (string $path) => $this->files->path($path))
            ->unique()
            ->values()
            ->all();

        $days = max(1, min(90, (int) ($arguments['days'] ?? 7)));
        $lines = max(1, min(300, (int) ($arguments['lines'] ?? 40)));
        $pattern = self::ERROR_PATTERN.(($arguments['include_warnings'] ?? false) ? '|warning|notice|deprecated' : '');
        $server = $this->files->server($arguments['server_id'] ?? null);

        $url = ($site->enable_ssl ? 'https' : 'http').'://'.$site->domain.'/';
        $minutes = $days * 1440;
        $roots = implode(' ', array_map('escapeshellarg', $directories));
        $fpmLog = $site->php_version && preg_match('/^\d+\.\d+$/', (string) $site->php_version) === 1
            ? escapeshellarg("/var/log/php{$site->php_version}-fpm.log")
            : '';

        $report = $this->files->run($server, implode("\n", array_filter([
            'echo '.escapeshellarg("### HTTP check: {$url}"),
            "curl -sk -o /dev/null -m 15 -w '%{http_code} in %{time_total}s\\n' ".escapeshellarg($url)." 2>&1 || echo 'request failed'",
            "echo '### Recent errors'",
            '{',
            '  :',
            $roots !== '' ? "  find {$roots} -maxdepth 5 \\( -name node_modules -o -name vendor -o -name .git \\) -prune -o -type f \\( -name '*.log' -o -name error_log -o -name php_errorlog \\) -size +0 -mmin -{$minutes} -print 2>/dev/null" : null,
            $fpmLog !== '' ? "  [ -f {$fpmLog} ] && echo {$fpmLog}" : null,
            '} | sort -u | head -n 20 | while IFS= read -r f; do',
            '  echo "=== $f ($(stat -c \'%s bytes, modified %y\' -- "$f" | cut -d. -f1))"',
            '  tail -n 5000 -- "$f" | grep -iE '.escapeshellarg($pattern)." | tail -n {$lines} | cut -c1-600",
            'done',
            'true',
        ])), $token, outputLimit: 60000);

        return [
            'domain' => $site->domain,
            'runtime' => $site->runtime ?: 'php',
            'searched' => $directories,
            'report' => $report,
            'hint' => ($site->runtime ?: 'php') === 'php'
                ? 'No log lines? PHP may log elsewhere: check display_errors/error_log with search_files in the site, or read_log_file for /var/log.'
                : 'Node/Python/Docker apps also log to the journal: use get_service_status and read_service_logs for the app\'s unit.',
        ];
    }
}
