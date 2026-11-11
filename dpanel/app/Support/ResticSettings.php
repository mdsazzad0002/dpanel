<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog W6: the server's restic repository. The password and the cloud
 * credentials are encrypted with the app key. Without the password nobody
 * can read the backups, the admin included, so the panel shows it once and
 * warns until the admin confirms it is kept somewhere else.
 */
class ResticSettings
{
    private const TABLE = 'backup_settings';

    private const KEY = 'restic';

    /**
     * @return array{repository: string, password: string, env: array<string, string>, key_confirmed_at: ?string,
     *   enabled: bool, schedule_time: string, keep_daily: int, keep_weekly: int, keep_monthly: int,
     *   last_run_at: ?string, last_run_status: ?string, last_run_message: ?string}
     */
    public function read(): array
    {
        $state = $this->raw();
        $secret = fn (string $field) => $this->decrypt((string) ($state[$field] ?? ''));
        $env = json_decode($secret('env') ?: '[]', true);

        return [
            'repository' => (string) ($state['repository'] ?? ''),
            'password' => $secret('password'),
            'env' => is_array($env) ? array_map('strval', $env) : [],
            'key_confirmed_at' => $state['key_confirmed_at'] ?? null,
            'enabled' => (bool) ($state['enabled'] ?? false),
            'schedule_time' => preg_match('/^\d{2}:\d{2}$/', (string) ($state['schedule_time'] ?? '')) ? (string) $state['schedule_time'] : '03:00',
            'keep_daily' => max(0, (int) ($state['keep_daily'] ?? 7)),
            'keep_weekly' => max(0, (int) ($state['keep_weekly'] ?? 4)),
            'keep_monthly' => max(0, (int) ($state['keep_monthly'] ?? 6)),
            'last_run_at' => $state['last_run_at'] ?? null,
            'last_run_status' => $state['last_run_status'] ?? null,
            'last_run_message' => $state['last_run_message'] ?? null,
        ];
    }

    public function configured(): bool
    {
        $state = $this->read();

        return $state['repository'] !== '' && $state['password'] !== '';
    }

    /** True while a generated key has not been confirmed as saved. */
    public function keyAtRisk(): bool
    {
        return $this->configured() && $this->read()['key_confirmed_at'] === null;
    }

    /** @param array<string, mixed> $changes plain values; password and env are encrypted here */
    public function write(array $changes): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $state = $this->raw();
        foreach ($changes as $field => $value) {
            $state[$field] = match ($field) {
                'password' => Crypt::encryptString((string) $value),
                'env' => Crypt::encryptString(json_encode((array) $value)),
                default => $value,
            };
        }
        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::KEY],
            ['setting_value' => json_encode($state, JSON_PRETTY_PRINT), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [];
            }
            $decoded = json_decode((string) DB::table(self::TABLE)->where('setting_key', self::KEY)->value('setting_value'), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // A different APP_KEY: the key is gone from here, not from the admin's copy.
            return '';
        }
    }
}
