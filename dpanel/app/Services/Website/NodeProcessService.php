<?php

namespace App\Services\Website;

use App\Models\Website;
use Illuminate\Support\Facades\Http;

class NodeProcessService
{
    public function __construct(protected WebsiteService $websiteService) {}

    /** @return array<string, mixed> */
    public function control(Website $website, string $action): array
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'status'], true)) {
            throw new \InvalidArgumentException("Unknown node process action: {$action}");
        }
        if (! $website->isNodeRuntime()) {
            throw new \RuntimeException('This website does not use the Node.js runtime.');
        }
        if (empty($website->node_port)) {
            throw new \RuntimeException('This website has no Node.js port assigned.');
        }

        $response = $this->request()->post($this->apiUrl(), [
            'site_id' => (string) $website->id,
            'action' => $action,
            'site_owner' => (string) $website->site_owner,
            'project_root' => (string) $website->project_root,
            'node_entry_file' => $website->node_entry_file,
            'node_start_command' => $website->node_start_command,
            'node_version' => $website->node_version,
            'port' => (int) $website->node_port,
        ]);

        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Node process control request failed.'));
        }

        if (in_array($action, ['start', 'restart'], true)) {
            $website->forceFill(['node_process_status' => 'running'])->saveQuietly();
        } elseif ($action === 'stop') {
            $website->forceFill(['node_process_status' => 'stopped'])->saveQuietly();
            $this->reloadGateway();
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    protected function request()
    {
        $request = Http::acceptJson()->asJson()->timeout(60);
        $token = trim((string) config('serverpanel.execution_api_token', ''));

        return $token !== '' ? $request->withToken($token) : $request;
    }

    protected function apiUrl(): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/').'/api/v1/node/control';
    }

    protected function reloadGateway(): void
    {
        app(\App\Services\EdgeGatewayReloader::class)->reload();
    }
}
