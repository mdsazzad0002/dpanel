<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SelfConnectionSettings
{
    private const TABLE = 'self_connection_settings';
    private const STATE_KEY = 'state';
    private const MAX_AUDIT_ENTRIES = 50;

    /**
     * @return array<string, mixed>
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
                'database' => array_replace($defaults['database'], is_array($decoded['database'] ?? null) ? $decoded['database'] : []),
                'redis' => array_replace($defaults['redis'], is_array($decoded['redis'] ?? null) ? $decoded['redis'] : []),
                'audit_log' => is_array($decoded['audit_log'] ?? null) ? array_values($decoded['audit_log']) : [],
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
        $current = $this->read();
        $normalized = [
            'database' => array_replace($current['database'], is_array($state['database'] ?? null) ? $state['database'] : []),
            'redis' => array_replace($current['redis'], is_array($state['redis'] ?? null) ? $state['redis'] : []),
            'audit_log' => is_array($state['audit_log'] ?? null) ? array_values($state['audit_log']) : $current['audit_log'],
        ];

        try {
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
        } catch (\Throwable) {
            // Settings persistence must never break the request that triggered it.
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function appendAudit(string $target, string $action, bool $success, array $context = []): void
    {
        $state = $this->read();

        $entry = array_merge([
            'at' => now()->toIso8601String(),
            'by_email' => auth()->user()?->email,
            'target' => $target,
            'action' => $action,
            'success' => $success,
        ], $context);

        $log = $state['audit_log'];
        $log[] = $entry;
        if (count($log) > self::MAX_AUDIT_ENTRIES) {
            $log = array_slice($log, -self::MAX_AUDIT_ENTRIES);
        }

        $this->write(['audit_log' => $log]);
    }

    public static function maskHost(?string $host): ?string
    {
        $host = trim((string) $host);
        if ($host === '') {
            return null;
        }

        if (strlen($host) <= 4) {
            return substr($host, 0, 1).'***';
        }

        return substr($host, 0, 2).'***'.substr($host, -2);
    }

    public static function maskUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        return substr($username, 0, 1).'***';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $target = [
            'last_tested_at' => null,
            'last_test_success' => null,
            'last_test_error' => null,
            'last_applied_at' => null,
            'last_applied_by' => null,
            'last_applied_success' => null,
            'last_applied_error' => null,
            'masked_host' => null,
            'masked_username' => null,
            'masked_database' => null,
            'previous_env_backup_path' => null,
        ];

        return [
            'database' => $target,
            'redis' => $target,
            'audit_log' => [],
        ];
    }
}
