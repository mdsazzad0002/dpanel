<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RemoteMysqlAccessService
{
    public function currentBindAddress(): string
    {
        $path = (string) config('remotemysql.bind_config_path');

        try {
            $contents = is_readable($path) ? file_get_contents($path) : false;
            if ($contents === false) {
                return (string) config('remotemysql.loopback_bind_address');
            }

            if (preg_match('/^\s*bind-address\s*=\s*(\S+)/mi', $contents, $m) === 1) {
                return trim($m[1]);
            }

            return (string) config('remotemysql.loopback_bind_address');
        } catch (\Throwable) {
            return (string) config('remotemysql.loopback_bind_address');
        }
    }

    public function isExternalBindEnabled(): bool
    {
        return $this->currentBindAddress() !== (string) config('remotemysql.loopback_bind_address');
    }

    /**
     * @return array{success: bool, changed?: bool, stage?: string, error?: string, backup?: string, rolled_back?: bool}
     */
    public function setBindAddress(string $address): array
    {
        $path = (string) config('remotemysql.bind_config_path');

        if ($this->currentBindAddress() === $address) {
            return ['success' => true, 'changed' => false];
        }

        try {
            $contents = is_readable($path) ? file_get_contents($path) : false;
            if ($contents === false) {
                return ['success' => false, 'stage' => 'read', 'error' => 'Unable to read the MySQL server config file.'];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'stage' => 'read', 'error' => $e->getMessage()];
        }

        $newContents = preg_match('/^\s*bind-address\s*=\s*\S+/mi', $contents) === 1
            ? preg_replace('/^(\s*bind-address\s*=\s*)\S+/mi', '${1}'.$address, $contents)
            : rtrim($contents)."\nbind-address = {$address}\n";

        try {
            $backupPath = $this->backup($path);
        } catch (\Throwable $e) {
            return ['success' => false, 'stage' => 'backup', 'error' => $e->getMessage()];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'dpanel-mysql-bind-');
        if ($temporary === false || file_put_contents($temporary, $newContents) === false) {
            return ['success' => false, 'stage' => 'write', 'error' => 'Unable to prepare the new MySQL config.', 'backup' => $backupPath];
        }

        try {
            $install = $this->runPrivileged(['/usr/local/sbin/dpanel-install-mysql-bind-config', $temporary]);
            if (! $install->successful()) {
                return ['success' => false, 'stage' => 'write', 'error' => $this->tail($install), 'backup' => $backupPath];
            }
        } finally {
            @unlink($temporary);
        }

        $restart = $this->runPrivileged(['systemctl', 'reload-or-restart', (string) config('remotemysql.service_name')]);
        if (! $restart->successful()) {
            $this->rollback($path, $backupPath);
            return ['success' => false, 'stage' => 'restart', 'error' => $this->tail($restart), 'backup' => $backupPath, 'rolled_back' => true];
        }

        usleep(500_000);

        $listening = $this->isListeningOn($address);
        if (! $listening) {
            $this->rollback($path, $backupPath);
            return ['success' => false, 'stage' => 'verify', 'error' => 'MySQL did not come back up listening on the expected address.', 'backup' => $backupPath, 'rolled_back' => true];
        }

        return ['success' => true, 'changed' => true, 'backup' => $backupPath];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function addFirewallRule(string $ip): array
    {
        $result = $this->runPrivileged(['dpanel', 'firewall', 'allow-ip', $ip, (string) config('remotemysql.port'), 'tcp']);

        return $result->successful() ? ['success' => true] : ['success' => false, 'error' => $this->tail($result)];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function removeFirewallRule(string $ip): array
    {
        $result = $this->runPrivileged(['dpanel', 'firewall', 'remove-ip', $ip, (string) config('remotemysql.port'), 'tcp']);

        return $result->successful() ? ['success' => true] : ['success' => false, 'error' => $this->tail($result)];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function restrictWildcard(): array
    {
        $result = $this->runPrivileged(['dpanel', 'firewall', 'remove-port', (string) config('remotemysql.port'), 'tcp']);

        return $result->successful() ? ['success' => true] : ['success' => false, 'error' => $this->tail($result)];
    }

    /**
     * @return array<int, string>
     */
    public function liveFirewallLines(): array
    {
        $result = $this->runPrivileged(['dpanel', 'firewall', 'rules']);
        if (! $result->successful()) {
            return [];
        }

        $port = (string) config('remotemysql.port');
        $lines = preg_split('/\r\n|\r|\n/', $result->output()) ?: [];

        return array_values(array_filter($lines, static fn (string $line) => str_contains($line, $port.'/tcp')));
    }

    private function isListeningOn(string $address): bool
    {
        $result = $this->runPrivileged(['ss', '-ltn']);
        if (! $result->successful()) {
            return false;
        }

        $port = (string) config('remotemysql.port');
        $wantsExternal = $address !== (string) config('remotemysql.loopback_bind_address');

        foreach (preg_split('/\r\n|\r|\n/', $result->output()) ?: [] as $line) {
            if (! str_contains($line, ':'.$port)) {
                continue;
            }

            $isLoopbackLine = str_contains($line, '127.0.0.1:'.$port);

            return $wantsExternal ? ! $isLoopbackLine : $isLoopbackLine;
        }

        return false;
    }

    private function backup(string $path): string
    {
        $dir = (string) config('remotemysql.backup_dir');
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the MySQL config backup directory.');
        }

        $backupPath = rtrim($dir, '/').'/'.basename($path).'.'.now()->format('Ymd_His').'.bak';

        if (! copy($path, $backupPath)) {
            throw new \RuntimeException('Unable to back up the MySQL config file before applying changes.');
        }

        chmod($backupPath, 0640);

        return $backupPath;
    }

    private function rollback(string $path, string $backupPath): void
    {
        try {
            $restore = $this->runPrivileged(['/usr/local/sbin/dpanel-install-mysql-bind-config', $backupPath]);
            if ($restore->successful()) {
                $this->runPrivileged(['systemctl', 'reload-or-restart', (string) config('remotemysql.service_name')]);
            }
        } catch (\Throwable $e) {
            Log::critical('RemoteMysqlAccessService rollback failed: '.$e->getMessage());
        }
    }

    private function runPrivileged(array $command)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return Process::timeout(30)->run($command);
        }

        return Process::timeout(30)->run(array_merge(['sudo', '-n'], $command));
    }

    private function tail($result): string
    {
        $output = trim($result->errorOutput() ?: $result->output());

        return $output !== '' ? substr($output, -500) : 'exit code '.$result->exitCode();
    }
}
