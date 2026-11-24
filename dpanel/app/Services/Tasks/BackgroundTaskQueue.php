<?php

namespace App\Services\Tasks;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One place for "we queued it, watch the card" work.
 *
 * Any long operation that should not hold a request writes a task here and
 * dispatches its job; the job moves the task through its stages, and the
 * floating task queue in the panel (BackgroundTaskController::index, polled by
 * useTaskQueue.js) shows a card per task. A task is only an entry in the cache:
 * the job stays the owner of the real work. acquireSlot() caps how many tasks
 * of a type run at once.
 *
 * Adding another operation needs no change here — pick a $type (the frontend
 * keys its card text off it), start(), then set() from the job:
 *
 *   $taskId = BackgroundTaskQueue::start('website_delete', $userId, [
 *       'title' => $website->domain,
 *       'data' => ['website_id' => $website->id],
 *   ]);
 *   BackgroundTaskQueue::set($taskId, ['stage' => self::STAGE_RUNNING]);
 */
class BackgroundTaskQueue
{
    public const STAGE_QUEUED = 'queued';

    public const STAGE_RUNNING = 'running';

    public const STAGE_DONE = 'done';

    public const STAGE_FAILED = 'failed';

    /** Stages a task can still move on from. */
    public const ACTIVE_STAGES = [self::STAGE_QUEUED, self::STAGE_RUNNING];

    /** Kept well past a job's own runtime so a late poll still sees the final state. */
    private const TTL_HOURS = 6;

    public static function key(string $taskId): string
    {
        return 'background-task:'.$taskId;
    }

    /**
     * One of a type's concurrency slots. A job holds a slot while it works, so
     * at most as many tasks of that type run at once as there are slots, and
     * the cap holds across every worker process.
     */
    public static function lockKey(string $type, int $slot = 0): string
    {
        return 'background-task-lock:'.$type.':'.$slot;
    }

    /**
     * How many tasks of a type may run at the same time, from
     * config('dpanel.background_tasks.concurrency').
     */
    public static function concurrency(string $type, int $default = 1): int
    {
        $configured = (int) config('dpanel.background_tasks.concurrency.'.$type, $default);

        return max(1, $configured);
    }

    /**
     * Take a free slot for this type, or null when they are all busy (the
     * caller then releases its job back to the queue and tries again later).
     * The lock is the caller's to release once the work is done.
     */
    public static function acquireSlot(string $type, int $seconds, ?int $slots = null): ?Lock
    {
        $slots = $slots ?? self::concurrency($type);

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock(self::lockKey($type, $slot), $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private static function indexKey(int $userId): string
    {
        return 'background-task-index:'.$userId;
    }

    /**
     * Record a queued task and return its id, which the job carries.
     *
     * @param  array{title?: string, subtitle?: string, message?: string, data?: array<string, mixed>}  $attributes
     */
    public static function start(string $type, int $userId, array $attributes = []): string
    {
        $taskId = (string) Str::uuid();

        self::write($taskId, array_merge([
            'title' => '',
            'subtitle' => '',
            'message' => '',
            'data' => [],
        ], $attributes, [
            'id' => $taskId,
            'type' => $type,
            'user_id' => $userId,
            'stage' => self::STAGE_QUEUED,
            'created_at' => now()->toIso8601String(),
        ]));

        $index = self::index($userId);
        $index[] = $taskId;
        Cache::put(self::indexKey($userId), array_values(array_unique($index)), now()->addHours(self::TTL_HOURS));

        return $taskId;
    }

    /**
     * Merge into an existing task (stage changes, messages) without losing what
     * start() recorded. Silently does nothing for a task already dismissed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function set(string $taskId, array $attributes): void
    {
        $current = self::get($taskId);
        if ($current === null) {
            return;
        }

        self::write($taskId, array_merge($current, $attributes));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $taskId): ?array
    {
        $task = Cache::get(self::key($taskId));

        return is_array($task) ? $task : null;
    }

    /**
     * Drop a card the user dismissed.
     */
    public static function forget(string $taskId, int $userId): void
    {
        Cache::forget(self::key($taskId));
        Cache::put(
            self::indexKey($userId),
            array_values(array_filter(self::index($userId), fn (string $id): bool => $id !== $taskId)),
            now()->addHours(self::TTL_HOURS),
        );
    }

    /**
     * Every task of this user that still has an entry, oldest first, optionally
     * of one type only. Ids whose entry has expired are pruned on the way.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, ?string $type = null): array
    {
        $index = self::index($userId);
        $live = [];
        $tasks = [];

        foreach ($index as $taskId) {
            $task = self::get($taskId);
            if ($task === null) {
                continue;
            }
            $live[] = $taskId;
            if ($type === null || (string) ($task['type'] ?? '') === $type) {
                $tasks[] = $task;
            }
        }

        if ($live !== $index) {
            Cache::put(self::indexKey($userId), $live, now()->addHours(self::TTL_HOURS));
        }

        return $tasks;
    }

    /**
     * The user's still-unfinished task of this type for a given data value, if
     * any — so a second click does not queue the same work twice.
     *
     * @return array<string, mixed>|null
     */
    public static function activeFor(int $userId, string $type, string $dataKey, string $dataValue): ?array
    {
        foreach (self::forUser($userId, $type) as $task) {
            $matches = (string) (($task['data'] ?? [])[$dataKey] ?? '') === $dataValue;
            if ($matches && in_array((string) ($task['stage'] ?? ''), self::ACTIVE_STAGES, true)) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private static function write(string $taskId, array $task): void
    {
        Cache::put(
            self::key($taskId),
            array_merge($task, ['updated_at' => now()->toIso8601String()]),
            now()->addHours(self::TTL_HOURS),
        );
    }

    /**
     * @return list<string>
     */
    private static function index(int $userId): array
    {
        $index = Cache::get(self::indexKey($userId));

        return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
    }
}
