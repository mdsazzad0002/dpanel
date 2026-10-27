<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;

class GetWebsiteTool implements McpTool
{
    public function name(): string
    {
        return 'get_website';
    }

    public function title(): string
    {
        return 'Website details';
    }

    public function description(): string
    {
        return 'Full configuration of one website by domain: document root, runtime (PHP/Node/Python/Docker) settings, process status, SSL certificate, aliases and subdomains. Use it to find where a site lives and how it is served before reading its logs.';
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
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        if ($domain === '') {
            throw new McpToolException('domain is required.');
        }

        $site = Website::query()
            ->visibleTo($token->user)
            ->whereIn('domain', [$domain, preg_replace('/^www\./', '', $domain)])
            ->with(['children:id,domain,type,status,parent_id', 'parent:id,domain'])
            ->first();

        if (! $site) {
            throw new McpToolException("No website found for {$domain}. Call list_websites to search.");
        }

        $certificate = SslCertificate::query()
            ->where('domain', $site->domain)
            ->latest('expires_at')
            ->first();

        $runtime = $site->runtime ?: 'php';

        return [
            'id' => $site->id,
            'domain' => $site->domain,
            'hostname' => $site->hostname,
            'type' => $site->type,
            'status' => $site->status,
            'scope' => $site->scope,
            'site_owner' => $site->site_owner,
            'root_path' => $site->root_path,
            'project_root' => $site->project_root,
            'runtime' => $runtime,
            'php_version' => $site->php_version,
            'php_fpm_socket' => $site->site_owner && $site->php_version
                ? "/run/php/dpanel-{$site->site_owner}-php{$site->php_version}.sock"
                : null,
            'runtime_settings' => match ($runtime) {
                'node' => [
                    'entry_file' => $site->node_entry_file,
                    'port' => $site->node_port,
                    'version' => $site->node_version,
                    'start_command' => $site->node_start_command,
                    'process_status' => $site->node_process_status,
                ],
                'python' => [
                    'entry_file' => $site->python_entry_file,
                    'port' => $site->python_port,
                    'version' => $site->python_version,
                    'start_command' => $site->python_start_command,
                    'mode' => $site->python_mode,
                    'workers' => $site->python_workers,
                    'process_status' => $site->python_process_status,
                ],
                'docker' => [
                    'image' => $site->docker_image,
                    'port' => $site->docker_port,
                    'container_port' => $site->docker_container_port,
                    'source' => $site->docker_source,
                    'process_status' => $site->docker_process_status,
                ],
                default => null,
            },
            'client_max_body_size' => $site->client_max_body_size,
            'ssl' => [
                'enabled' => (bool) $site->enable_ssl,
                'mode' => $site->ssl_mode,
                'certificate' => $certificate ? [
                    'type' => $certificate->type,
                    'status' => $certificate->status,
                    'issued_at' => $certificate->issued_at?->toIso8601String(),
                    'expires_at' => $certificate->expires_at?->toIso8601String(),
                    'auto_renew' => (bool) $certificate->auto_renew,
                ] : null,
            ],
            'manage_dns' => (bool) $site->manage_dns,
            'parent' => $site->parent?->domain,
            'children' => $site->children->map(fn (Website $child) => [
                'domain' => $child->domain,
                'type' => $child->type,
                'status' => $child->status,
            ])->values()->all(),
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }
}
