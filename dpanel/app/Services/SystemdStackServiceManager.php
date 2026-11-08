<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Shows the status of one stack's services and turns them on or off, both
 * through drust (backlog D3: the panel runs no `systemctl` itself).
 * Subclasses name the stack: its config file and drust endpoint.
 */
abstract class SystemdStackServiceManager
{
    /** Config file holding the services, and the drust endpoint segment. */
    protected string $stack;

    /**
     * @return array<string, array{key: string, label: string, unit: string, installed: bool, active: bool, enabled: bool, state: string}>
     */
    public function statuses(): array
    {
        $services = $this->services();
        $units = array_values(array_unique(array_map(fn ($service) => (string) $service['unit'], $services)));
        $states = $this->unitStates($units);

        $statuses = [];
        foreach ($services as $key => $service) {
            $unit = (string) $service['unit'];
            $state = $states[$unit] ?? [];
            $statuses[$key] = [
                'key' => (string) $key,
                'label' => (string) $service['label'],
                'unit' => $unit,
                'installed' => (bool) ($state['installed'] ?? false),
                'active' => (bool) ($state['active'] ?? false),
                'enabled' => (bool) ($state['enabled'] ?? false),
                'state' => (string) ($state['state'] ?? 'unknown') ?: 'unknown',
            ];
        }

        return $statuses;
    }

    public function isKnownService(string $service): bool
    {
        return array_key_exists($service, $this->services());
    }

    /** The stack's services, keyed by the name the panel and drust use. */
    protected function services(): array
    {
        return (array) config($this->stack.'.services');
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

        // Enabling a service (pgAdmin first start, plus drust's stay-up check)
        // can take a while.
        $request = Http::acceptJson()->asJson()->timeout(120);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/'.$this->stack.'/service', [
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
     * drust's `GET /system/services`; nothing known when drust does not answer.
     *
     * @param  list<string>  $units
     * @return array<string, array<string, mixed>>
     */
    private function unitStates(array $units): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($units === [] || $baseUrl === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(15)
                ->withToken(trim((string) config('serverpanel.execution_api_token', '')))
                ->get(rtrim($baseUrl, '/').'/system/services', ['services' => implode(',', $units)]);
        } catch (\Throwable) {
            return [];
        }

        $services = $response->json('services');

        return $response->successful() && is_array($services) ? $services : [];
    }
}
