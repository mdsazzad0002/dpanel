<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class PostgresqlServiceManager
{
    /**
     * @return array<string, array{key: string, label: string, unit: string, installed: bool, active: bool, enabled: bool, state: string}>
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach ((array) config('postgresql.services') as $key => $service) {
            $statuses[$key] = $this->status((string) $key, (string) $service['label'], (string) $service['unit']);
        }

        return $statuses;
    }

    public function isKnownService(string $service): bool
    {
        return array_key_exists($service, (array) config('postgresql.services'));
    }

    /**
     * Turn a service on (start + enable at boot) or off (stop + disable at boot)
     * through drust. php-fpm runs with ProtectSystem=full, so /etc is read-only
     * for anything it spawns (sudo included) and `systemctl enable` can't work
     * from here; drust runs outside that sandbox.
     *
     * @return array{success: bool, error?: string}
     */
    public function setRunning(string $service, bool $running): array
    {
        if (! $this->isKnownService($service)) {
            return ['success' => false, 'error' => 'Unknown service.'];
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return ['success' => false, 'error' => 'drust API is not configured.'];
        }

        // Enabling pgAdmin can take a while on first start.
        $request = Http::acceptJson()->asJson()->timeout(120);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/postgresql/service', [
                'service' => $service,
                'enabled' => $running,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'drust API request failed: '.$e->getMessage()];
        }

        $json = $response->json();
        if ($response->successful() && is_array($json) && ($json['success'] ?? false)) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => (string) ((is_array($json) ? ($json['message'] ?? null) : null) ?: $response->body() ?: 'drust API failed.')];
    }

    /**
     * @return array{key: string, label: string, unit: string, installed: bool, active: bool, enabled: bool, state: string}
     */
    private function status(string $key, string $label, string $unit): array
    {
        $loadState = $this->systemctl(['show', '--property=LoadState', '--value', $unit]);
        $active = $this->systemctl(['is-active', $unit]);
        $enabled = $this->systemctl(['is-enabled', $unit]);

        return [
            'key' => $key,
            'label' => $label,
            'unit' => $unit,
            'installed' => $loadState === 'loaded',
            'active' => $active === 'active',
            'enabled' => $enabled === 'enabled',
            'state' => $active !== '' ? $active : 'unknown',
        ];
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function systemctl(array $arguments): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return '';
        }

        try {
            return trim(Process::timeout(10)->run(array_merge(['systemctl'], $arguments))->output());
        } catch (\Throwable) {
            return '';
        }
    }
}
