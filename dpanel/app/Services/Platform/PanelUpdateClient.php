<?php

namespace App\Services\Platform;

use App\Services\PanelUpdate\PanelUpdateRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * drust's `/api/v1/panel-update/runs` (backlog U6). drust runs the update as
 * its own systemd unit, because the update replaces the panel and restarts
 * drust; this only starts runs and reads them back.
 */
class PanelUpdateClient
{
    /** @return array{active: ?string, runs: list<array<string, mixed>>} */
    public function runs(): array
    {
        $json = $this->check($this->send(fn () => $this->request()->get($this->url())));

        return ['active' => $json['active'] ?? null, 'runs' => array_values(array_filter((array) ($json['runs'] ?? []), 'is_array'))];
    }

    /** @return array{run: array<string, mixed>, log: string, offset: int} */
    public function run(string $id, int $offset = 0): array
    {
        $json = $this->check($this->send(fn () => $this->request()->get($this->url('/'.rawurlencode($id)), ['offset' => max(0, $offset)])));

        return ['run' => (array) ($json['run'] ?? []), 'log' => (string) ($json['log'] ?? ''), 'offset' => (int) ($json['offset'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $request  `action` and what it needs
     * @return array<string, mixed> the new run
     */
    public function start(array $request): array
    {
        $json = $this->check($this->send(fn () => $this->request()->post($this->url(), $request)));

        return (array) ($json['run'] ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->timeout(max(30, (int) config('dpanel.execution_api_timeout', 60)))
            ->withToken(trim((string) config('dpanel.execution_api_token', '')));
    }

    private function url(string $suffix = ''): string
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new PanelUpdateRefused('drust execution API is not configured.', 503);
        }

        return rtrim($baseUrl, '/').'/api/v1/panel-update/runs'.$suffix;
    }

    /** drust restarts during an update, so being unreachable is expected for a moment. */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $error) {
            throw new PanelUpdateRefused('drust is not answering; it restarts during an update. Try again in a moment.', 503, $error);
        }
    }

    /** @return array<string, mixed> */
    private function check(Response $response): array
    {
        $json = $response->json();
        if ($response->status() === 501) {
            throw new PanelUpdateRefused('Updating from the panel is not available on this server.', 501);
        }
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            // 400 is drust refusing the request itself, such as a rollback with no backup.
            $status = match ($response->status()) {
                400 => 422,
                404, 409 => $response->status(),
                default => 502,
            };

            throw new PanelUpdateRefused($message !== '' ? $message : 'drust answered '.$response->status().'.', $status);
        }

        return $json;
    }
}
