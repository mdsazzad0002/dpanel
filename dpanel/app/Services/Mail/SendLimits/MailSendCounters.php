<?php

namespace App\Services\Mail\SendLimits;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Reads the counters Rspamd's dpanel_ratelimit.lua keeps in Redis (P5).
 * Windows are fixed UTC hours and days, the same ones the plugin uses.
 */
class MailSendCounters
{
    public static function hourWindow(CarbonInterface $now): string
    {
        return $now->copy()->utc()->format('YmdH');
    }

    public static function dayWindow(CarbonInterface $now): string
    {
        return $now->copy()->utc()->format('Ymd');
    }

    /**
     * @param  list<string>  $accounts  account keys (`a<user id>`)
     * @return array<string, array{hour: int, day: int, hit: string|null}>|null null when Redis cannot be read
     */
    public function read(array $accounts, CarbonInterface $now): ?array
    {
        if ($accounts === []) {
            return [];
        }
        $hour = self::hourWindow($now);
        $day = self::dayWindow($now);
        $keys = [];
        foreach ($accounts as $account) {
            array_push($keys, "dpanel:mail:{$account}:h:{$hour}", "dpanel:mail:{$account}:d:{$day}", "dpanel:mail:{$account}:hit");
        }

        try {
            $values = Redis::connection('mail_limits')->mget($keys);
        } catch (Throwable) {
            return null;
        }

        $counts = [];
        foreach (array_values($accounts) as $i => $account) {
            $hit = $values[$i * 3 + 2] ?? null;
            $counts[$account] = [
                'hour' => (int) ($values[$i * 3] ?? 0),
                'day' => (int) ($values[$i * 3 + 1] ?? 0),
                'hit' => is_string($hit) && $hit !== '' ? $hit : null,
            ];
        }

        return $counts;
    }
}
