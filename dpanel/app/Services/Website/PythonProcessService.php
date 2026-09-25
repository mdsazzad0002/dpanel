<?php

namespace App\Services\Website;

use App\Models\Website;
use Illuminate\Support\Facades\Http;

class PythonProcessService
{
    public function __construct(protected WebsiteService $websiteService) {}

    /** @return array<string, mixed> */
    public function control(Website $website, string $action): array
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'status'], true)) {
            throw new \InvalidArgumentException("Unknown python process action: {$action}");
        }
        if (! $website->isPythonRuntime()) {
            throw new \RuntimeException('This website does not use the Python runtime.');
        }
        if (empty($website->python_port)) {
            throw new \RuntimeException('This website has no Python port assigned.');
        }

        $response = $this->request()->post($this->apiUrl(), [
            'site_id' => (string) $website->id,
            'action' => $action,
            'site_owner' => (string) $website->site_owner,
            'project_root' => (string) $website->project_root,
            'python_entry_file' => $website->python_entry_file,
            'python_start_command' => $website->python_start_command,
            'python_version' => $website->python_version,
            'port' => (int) $website->python_port,
        ]);

        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Python process control request failed.'));
        }

        if (in_array($action, ['start', 'restart'], true)) {
            $website->forceFill(['python_process_status' => 'running'])->saveQuietly();
        } elseif ($action === 'stop') {
            $website->forceFill(['python_process_status' => 'stopped'])->saveQuietly();
            $this->reloadGateway($website);
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

        return rtrim($baseUrl, '/').'/api/v1/python/control';
    }

    protected function reloadGateway(Website $website): void
    {
        app(\App\Services\EdgeGatewayReloader::class)->reloadDomains([(string) $website->domain]);
    }
}
