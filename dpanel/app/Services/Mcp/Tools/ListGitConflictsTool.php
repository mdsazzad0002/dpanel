<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use App\Services\Website\WebsiteGitService;

class ListGitConflictsTool implements McpTool
{
    use GitConflictsSupport;

    public function __construct(private readonly WebsiteGitService $git) {}

    public function name(): string
    {
        return 'list_git_conflicts';
    }

    public function title(): string
    {
        return 'Git sync conflicts';
    }

    public function description(): string
    {
        return 'For a website deployed from Git whose pull/push/sync stopped because the live site and the repository both changed: compares them without changing anything and returns each file whose changes collide, with its base, live (server) and remote (repository) versions and a merge with conflict markers. Read them, then call resolve_git_conflicts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'The website domain, e.g. example.com.'],
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
        try {
            return $this->git->conflicts($this->deploymentFor($arguments, $token));
        } catch (\RuntimeException $e) {
            throw new McpToolException($e->getMessage());
        }
    }
}
