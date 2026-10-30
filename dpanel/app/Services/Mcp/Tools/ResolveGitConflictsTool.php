<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use App\Services\Website\WebsiteGitService;

class ResolveGitConflictsTool implements McpTool
{
    use GitConflictsSupport;

    public function __construct(private readonly WebsiteGitService $git) {}

    public function name(): string
    {
        return 'resolve_git_conflicts';
    }

    public function title(): string
    {
        return 'Resolve Git sync conflicts';
    }

    public function description(): string
    {
        return 'Merges the repository into the live website on the server. It does not push: the result waits on the website\'s Git page until an admin reviews it and confirms the push (or undoes it), so tell the user to do that. The live changes are committed first, so nothing is lost from history. strategy "live" keeps the server side of each conflict, "remote" the repository side (the developer\'s pushed code), "both" keeps both sides\' lines; changes that do not conflict always merge. With "custom", give every file from list_git_conflicts in files, each with take (live/remote/both) or the complete final content you wrote. Call list_git_conflicts first and only write content you are confident in.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'The website domain, e.g. example.com.'],
                'strategy' => ['type' => 'string', 'enum' => WebsiteGitService::STRATEGIES],
                'files' => [
                    'type' => 'array',
                    'description' => 'strategy custom only: one entry per conflicting file.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'take' => ['type' => 'string', 'enum' => ['live', 'remote', 'both']],
                            'content' => ['type' => 'string', 'description' => 'The complete final file. Wins over take.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            'required' => ['domain', 'strategy'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $deployment = $this->deploymentFor($arguments, $token);
        $strategy = (string) ($arguments['strategy'] ?? '');
        $resolutions = [];
        foreach ((array) ($arguments['files'] ?? []) as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($path === '') {
                throw new McpToolException('Every file needs a path.');
            }
            $choice = array_filter([
                'take' => isset($file['take']) ? (string) $file['take'] : null,
                'content' => isset($file['content']) ? (string) $file['content'] : null,
            ], fn ($value) => $value !== null);
            if ($choice === [] || (isset($choice['take']) && ! in_array($choice['take'], ['live', 'remote', 'both'], true))) {
                throw new McpToolException("{$path}: give take (live, remote or both) or content.");
            }
            $resolutions[$path] = $choice;
        }
        if ($strategy === 'custom' && $resolutions === []) {
            throw new McpToolException('strategy custom needs files.');
        }

        try {
            $result = $this->git->resolve($deployment, $strategy, $resolutions, $token->user_id);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new McpToolException($e->getMessage());
        }
        if (! $result['success']) {
            throw new McpToolException($result['output']);
        }

        return ['resolved' => true, 'pushed' => false, 'next' => 'An admin must review and confirm the push on the website\'s Git page (Deployment → Resolve conflicts), or undo it.', 'output' => $result['output']];
    }
}
