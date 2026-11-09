<?php

namespace App\Services;

use App\Models\UsageAlert;
use App\Models\User;

/**
 * Backlog P6: one notice when an account reaches 80% of a package limit and
 * one when it reaches 100%, for disk, monthly bandwidth, memory and CPU.
 *
 * Each level is sent once. It can be sent again only after use has fallen
 * clearly below it (10 points, so a value hovering at the line does not send
 * a notice every check), or, for bandwidth, when a new month starts. Going
 * straight past both lines sends only the 100% notice.
 */
class UsageAlertService
{
    public const WARNING = 80;

    public const LIMIT = 100;

    private const HYSTERESIS = 10;

    /** @var array<string, array{type: string, name: string}> */
    private const RESOURCES = [
        'disk' => ['type' => 'disk_quota', 'name' => 'Disk space'],
        'bandwidth' => ['type' => 'bandwidth_limit', 'name' => 'Monthly bandwidth'],
        'memory' => ['type' => 'memory_limit', 'name' => 'Memory'],
        'cpu' => ['type' => 'cpu_limit', 'name' => 'CPU'],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    public static function level(float $used, float $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $percent = $used / $limit * 100;

        return match (true) {
            $percent >= self::LIMIT => self::LIMIT,
            $percent >= self::WARNING => self::WARNING,
            default => 0,
        };
    }

    /**
     * Compares one reading with its limit and sends the notice it calls for.
     *
     * @param  string  $usage  how the use reads in the message, such as "1200 MB of 1000 MB"
     * @param  string  $note  added to the 100% message, such as what was done about it
     * @param  array<string, mixed>  $data
     * @return int the level a notice was sent for, or 0
     */
    public function record(User $user, string $resource, float $used, float $limit, string $usage, string $period = '', string $note = '', array $data = []): int
    {
        $alert = UsageAlert::query()->firstOrNew(['user_id' => $user->id, 'resource' => $resource]);
        $sent = $alert->exists && $alert->period === $period ? (int) $alert->level : 0;
        $level = self::level($used, $limit);

        if ($level <= $sent) {
            // Lower the mark only once use is clearly under the line it was sent for.
            $percent = $limit > 0 ? $used / $limit * 100 : 0;
            if ($alert->exists && $sent > 0 && $percent < $sent - self::HYSTERESIS) {
                $level === 0 ? $alert->delete() : $alert->fill(['level' => $level])->save();
            }

            return 0;
        }

        $alert->fill(['period' => $period, 'level' => $level, 'notified_at' => now()])->save();
        $this->notify($user, $resource, $level, $usage, $note, $data + ['used' => $used, 'limit' => $limit]);

        return $level;
    }

    /** @param  array<string, mixed>  $data */
    private function notify(User $user, string $resource, int $level, string $usage, string $note, array $data): void
    {
        $meta = self::RESOURCES[$resource] ?? ['type' => "{$resource}_limit", 'name' => ucfirst($resource)];
        $full = $level >= self::LIMIT;
        $this->notifications->create(
            type: $meta['type'],
            status: $full ? 'blocked' : 'warning',
            title: $full ? "{$meta['name']} limit reached" : "{$meta['name']} {$level}% used",
            message: trim("{$user->name} uses {$usage}.".($full && $note !== '' ? " {$note}" : '')),
            assignedUserId: $user->id,
            assignedResellerId: $user->reseller_id,
            subject: $user,
            data: ['resource' => $resource, 'level' => $level] + $data,
        );
    }
}
