<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackupSettings
{
    private const TABLE = 'backup_settings';
    private const STATE_KEY = 'state';

    /**
     * @return array{
     *   schedule_enabled: bool,
     *   schedule_time: string,
     *   retention_days: int,
     *   remote_upload_enabled: bool,
     *   remote_host: string,
     *   remote_port: int,
     *   remote_user: string,
     *   remote_path: string,
     *   remote_ssh_key_path: string,
     *   remote_strict_host_checking: bool,
     *   remote_ssh_path: string,
     *   remote_scp_path: string
     * }
     */
    public function read(): array
    {
        $defaults = $this->defaults();

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $defaults;
            }

            $raw = DB::table(self::TABLE)
                ->where('setting_key', self::STATE_KEY)
                ->value('setting_value');

            if (! is_string($raw) || trim($raw) === '') {
                return $defaults;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $defaults;
            }

            return [
                'schedule_enabled' => $this->asBool($decoded['schedule_enabled'] ?? $defaults['schedule_enabled']),
                'schedule_time' => $this->normalizeTime((string) ($decoded['schedule_time'] ?? $defaults['schedule_time'])),
                'retention_days' => max(1, (int) ($decoded['retention_days'] ?? $defaults['retention_days'])),
                'remote_upload_enabled' => $this->asBool($decoded['remote_upload_enabled'] ?? $defaults['remote_upload_enabled']),
                'remote_host' => trim((string) ($decoded['remote_host'] ?? $defaults['remote_host'])),
                'remote_port' => max(1, (int) ($decoded['remote_port'] ?? $defaults['remote_port'])),
                'remote_user' => trim((string) ($decoded['remote_user'] ?? $defaults['remote_user'])),
                'remote_path' => trim((string) ($decoded['remote_path'] ?? $defaults['remote_path'])),
                'remote_ssh_key_path' => trim((string) ($decoded['remote_ssh_key_path'] ?? $defaults['remote_ssh_key_path'])),
                'remote_strict_host_checking' => $this->asBool($decoded['remote_strict_host_checking'] ?? $defaults['remote_strict_host_checking']),
                'remote_ssh_path' => trim((string) ($decoded['remote_ssh_path'] ?? $defaults['remote_ssh_path'])),
                'remote_scp_path' => trim((string) ($decoded['remote_scp_path'] ?? $defaults['remote_scp_path'])),
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    public function write(array $state): void
    {
        $normalized = [
            'schedule_enabled' => $this->asBool($state['schedule_enabled'] ?? true),
            'schedule_time' => $this->normalizeTime((string) ($state['schedule_time'] ?? '02:30')),
            'retention_days' => max(1, (int) ($state['retention_days'] ?? 7)),
            'remote_upload_enabled' => $this->asBool($state['remote_upload_enabled'] ?? false),
            'remote_host' => trim((string) ($state['remote_host'] ?? '')),
            'remote_port' => max(1, (int) ($state['remote_port'] ?? 22)),
            'remote_user' => trim((string) ($state['remote_user'] ?? '')),
            'remote_path' => trim((string) ($state['remote_path'] ?? '')),
            'remote_ssh_key_path' => trim((string) ($state['remote_ssh_key_path'] ?? '')),
            'remote_strict_host_checking' => $this->asBool($state['remote_strict_host_checking'] ?? true),
            'remote_ssh_path' => trim((string) ($state['remote_ssh_path'] ?? 'ssh')),
            'remote_scp_path' => trim((string) ($state['remote_scp_path'] ?? 'scp')),
        ];

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::STATE_KEY],
            [
                'setting_value' => json_encode($normalized, JSON_PRETTY_PRINT),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'schedule_enabled' => $this->envBool('BACKUP_SCHEDULE_ENABLED', true),
            'schedule_time' => $this->normalizeTime((string) env('BACKUP_TIME', '02:30')),
            'retention_days' => max(1, (int) env('BACKUP_RETENTION_DAYS', 7)),
            'remote_upload_enabled' => $this->envBool('BACKUP_REMOTE_UPLOAD_ENABLED', false),
            'remote_host' => trim((string) env('BACKUP_REMOTE_HOST', '')),
            'remote_port' => max(1, (int) env('BACKUP_REMOTE_PORT', 22)),
            'remote_user' => trim((string) env('BACKUP_REMOTE_USER', '')),
            'remote_path' => trim((string) env('BACKUP_REMOTE_PATH', '')),
            'remote_ssh_key_path' => trim((string) env('BACKUP_REMOTE_SSH_KEY_PATH', '')),
            'remote_strict_host_checking' => $this->envBool('BACKUP_REMOTE_STRICT_HOST_CHECKING', true),
            'remote_ssh_path' => trim((string) env('BACKUP_REMOTE_SSH_PATH', 'ssh')),
            'remote_scp_path' => trim((string) env('BACKUP_REMOTE_SCP_PATH', 'scp')),
        ];
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param mixed $value
     */
    private function asBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return '02:30';
    }
}
