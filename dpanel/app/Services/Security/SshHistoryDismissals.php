<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which SSH login history rows the admin deleted from the panel view.
 *
 * The history is read live from the systemd journal, which fail2ban also reads,
 * so nothing is removed there. Instead this remembers "hide everything up to
 * now" per IP, or for all IPs; a newer attempt from the same IP shows again.
 */
class SshHistoryDismissals
{
    private const TABLE = 'security_settings';
    private const KEY = 'ssh_history_dismissals';

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{events: array<int, array<string, mixed>>, hidden: int, cleared_at: int|null}
     */
    public function filter(array $events): array
    {
        $state = $this->read();
        $visible = array_values(array_filter($events, function ($event) use ($state) {
            $time = (int) ($event['time'] ?? 0);
            $before = max((int) $state['cleared_before'], (int) ($state['ips'][(string) ($event['ip'] ?? '')] ?? 0));

            return $time > $before;
        }));

        return [
            'events' => $visible,
            'hidden' => count($events) - count($visible),
            'cleared_at' => $state['cleared_before'] ?: null,
        ];
    }

    /**
     * @param  array<int, string>  $ips
     */
    public function dismissIps(array $ips): void
    {
        $state = $this->read();
        $now = now()->getTimestamp();
        foreach ($ips as $ip) {
            $state['ips'][$ip] = $now;
        }
        $this->write($state);
    }

    public function clearAll(): void
    {
        // A clear covers every per-IP entry made before it.
        $this->write(['cleared_before' => now()->getTimestamp(), 'ips' => []]);
    }

    public function restore(): void
    {
        $this->write(['cleared_before' => 0, 'ips' => []]);
    }

    /**
     * @return array{cleared_before: int, ips: array<string, int>}
     */
    private function read(): array
    {
        $empty = ['cleared_before' => 0, 'ips' => []];
        if (! Schema::hasTable(self::TABLE)) {
            return $empty;
        }
        $decoded = json_decode((string) DB::table(self::TABLE)->where('setting_key', self::KEY)->value('setting_value'), true);
        if (! is_array($decoded)) {
            return $empty;
        }

        return [
            'cleared_before' => (int) ($decoded['cleared_before'] ?? 0),
            'ips' => array_map('intval', (array) ($decoded['ips'] ?? [])),
        ];
    }

    /**
     * @param  array{cleared_before: int, ips: array<string, int>}  $state
     */
    private function write(array $state): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \RuntimeException('The security_settings table is missing; run the migrations.');
        }
        // Entries already covered by a full clear are dead weight.
        $state['ips'] = array_filter($state['ips'], fn (int $time) => $time > $state['cleared_before']);

        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::KEY],
            ['setting_value' => json_encode($state), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
