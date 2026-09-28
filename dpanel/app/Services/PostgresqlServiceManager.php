<?php

namespace App\Services;

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
     * through the dscript postgresql module.
     *
     * @return array{success: bool, error?: string}
     */
    public function setRunning(string $service, bool $running): array
    {
        if (! $this->isKnownService($service)) {
            return ['success' => false, 'error' => 'Unknown service.'];
        }

        $result = $this->runPrivileged(['dpanel', 'postgresql', $running ? 'start' : 'stop', $service]);

        return $result->successful() ? ['success' => true] : ['success' => false, 'error' => $this->tail($result)];
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

    private function runPrivileged(array $command)
    {
        // Enabling pgAdmin can take a while on first start.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return Process::timeout(120)->run($command);
        }

        return Process::timeout(120)->run(array_merge(['sudo', '-n'], $command));
    }

    private function tail($result): string
    {
        $output = trim($result->errorOutput() ?: $result->output());

        return $output !== '' ? substr($output, -500) : 'exit code '.$result->exitCode();
    }
}
