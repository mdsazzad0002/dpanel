<?php

namespace App\Services\Docker;

use Illuminate\Support\Facades\Http;

class DrustDockerClient
{
    /** Where drust looks for the docker CLI. */
    private const CLI_PATHS = ['/usr/bin/docker', '/usr/local/bin/docker'];

    /**
     * Whether Docker is on this server at all. Docker is an optional add-on
     * (`sudo dpanel docker`), so the panel hides its menu until it is.
     */
    public function installed(): bool
    {
        foreach (self::CLI_PATHS as $path) {
            if (@is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Docker is installed and running, with every container and image.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->send('/api/v1/docker', null, 30)['data'];
    }

    /**
     * @param  'start'|'stop'|'restart'|'remove'|'run'|'pull'|'remove_image'|'prune_images'  $action
     * @param  array<string, mixed>  $payload
     * @return array{message: string, data: array<string, mixed>} the Docker status after the change
     */
    public function action(string $action, array $payload = []): array
    {
        // Pulling a large image, or running one that has to be pulled first, takes minutes.
        $timeout = in_array($action, ['pull', 'run'], true) ? 900 : 120;

        return $this->send('/api/v1/docker', ['action' => $action] + $payload, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    public function logs(string $id, int $lines): array
    {
        return $this->send('/api/v1/docker/logs', ['id' => $id, 'lines' => $lines], 60)['data'];
    }

    /**
     * GET when there is no payload, POST otherwise.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{message: string, data: array<string, mixed>}
     */
    private function send(string $path, ?array $payload, int $timeout): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured (SERVERPANEL_EXECUTION_API_BASE_URL).');
        }

        $request = Http::acceptJson()->asJson()->timeout($timeout);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $url = rtrim($baseUrl, '/').$path;
        try {
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'drust request failed.'));
        }

        return ['message' => (string) ($json['message'] ?? ''), 'data' => (array) ($json['data'] ?? [])];
    }
}
