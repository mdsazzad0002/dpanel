<?php

namespace App\Services\Projects;

use App\Models\AppProject;
use Illuminate\Support\Facades\Http;

/** Starts/stops a project's systemd unit through drust's node/python control API. */
class AppProjectProcessService
{
    public const ACTIONS = ['start', 'stop', 'restart', 'status', 'remove'];

    /** @return array<string, mixed> */
    public function control(AppProject $project, string $action): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown project action: {$action}");
        }
        // A restart of a stopped (disabled) unit would not re-enable it, so
        // it would not come back after a reboot: start it instead.
        if ($action === 'restart' && $project->status === 'stopped') {
            $action = 'start';
        }

        $node = $project->runtime === 'node';
        $payload = [
            'site_id' => (string) $project->unit_key,
            'action' => $action,
            'site_owner' => (string) $project->site_owner,
            'project_root' => (string) $project->working_directory,
            'port' => (int) $project->port,
        ];
        $payload += $node
            ? [
                'node_entry_file' => $project->entry_file,
                'node_start_command' => $project->start_command,
                'node_version' => $project->version,
            ]
            : [
                'python_entry_file' => $project->entry_file,
                'python_start_command' => $project->start_command,
                'python_version' => $project->version,
                'workers' => $project->pythonWorkerCount(),
                'mode' => $project->pythonMode(),
                'timeout' => $project->pythonTimeout(),
            ];

        // A Python start may create the venv and pip install requirements.
        $timeout = in_array($action, ['start', 'restart'], true) ? ($node ? 90 : 280) : 60;
        $response = $this->request($timeout)->post($this->apiUrl($node ? 'node' : 'python'), $payload);

        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Project process control request failed.'));
        }

        if (in_array($action, ['start', 'restart'], true)) {
            $project->forceFill(['status' => 'running'])->saveQuietly();
        } elseif (in_array($action, ['stop', 'remove'], true)) {
            $project->forceFill(['status' => 'stopped'])->saveQuietly();
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    protected function request(int $timeout)
    {
        $request = Http::acceptJson()->asJson()->timeout($timeout);
        $token = trim((string) config('serverpanel.execution_api_token', ''));

        return $token !== '' ? $request->withToken($token) : $request;
    }

    protected function apiUrl(string $runtime): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/')."/api/v1/{$runtime}/control";
    }
}
