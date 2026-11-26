<?php

namespace App\Services\Tasks;

use App\Models\User;
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

    /** Index of every user's tasks, for the cross-user queue view. */
    private const ALL_INDEX_KEY = 'background-task-index:all';

    /** How many ids that index keeps; the oldest fall off. */
    private const ALL_INDEX_LIMIT = 300;

    /**
     * Feature stages (CloneShareJobStatus and the other *JobStatus stores use
     * their own words) mapped onto the four stages a card knows. Anything not
     * listed is work in progress.
     */
    private const STAGE_ALIASES = [
        'queued' => self::STAGE_QUEUED,
        'pending' => self::STAGE_QUEUED,
        'waiting' => self::STAGE_QUEUED,
        'done' => self::STAGE_DONE,
        'ready' => self::STAGE_DONE,
        'complete' => self::STAGE_DONE,
        'completed' => self::STAGE_DONE,
        'finished' => self::STAGE_DONE,
        'success' => self::STAGE_DONE,
        'failed' => self::STAGE_FAILED,
        'error' => self::STAGE_FAILED,
        'cancelled' => self::STAGE_FAILED,
        'canceled' => self::STAGE_FAILED,
    ];

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
    public static function start(string $type, int $userId, array $attributes = [], ?string $taskId = null): string
    {
        // An operation that already has an id of its own (a clone id, an
        // export id) passes it here, so its job can report progress with the
        // id it already carries and needs no new constructor argument.
        $taskId = $taskId !== null && trim($taskId) !== '' ? trim($taskId) : (string) Str::uuid();

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

        self::remember(self::indexKey($userId), $taskId);
        // A second index of everyone's tasks, so an admin's queue window can
        // show the work other users have waiting (BackgroundTaskController).
        self::remember(self::ALL_INDEX_KEY, $taskId, self::ALL_INDEX_LIMIT);

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
        Cache::put(
            self::ALL_INDEX_KEY,
            array_values(array_filter(self::readIndex(self::ALL_INDEX_KEY), fn (string $id): bool => $id !== $taskId)),
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
        return self::readIndex(self::indexKey($userId));
    }

    /**
     * @return list<string>
     */
    private static function readIndex(string $key): array
    {
        $index = Cache::get($key);

        return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
    }

    /**
     * Append a task id to an index, newest last, under a short lock so two
     * jobs finishing at once do not drop each other's entry.
     */
    private static function remember(string $key, string $taskId, ?int $limit = null): void
    {
        $lock = Cache::lock($key.':lock', 5);
        $locked = false;

        try {
            $locked = $lock->block(2);
        } catch (\Throwable $e) {
            // Could not take the lock in time: write anyway rather than lose
            // the task, accepting the small chance of a lost id.
        }

        try {
            $index = self::readIndex($key);
            $index[] = $taskId;
            $index = array_values(array_unique($index));

            if ($limit !== null && count($index) > $limit) {
                $index = array_slice($index, -$limit);
            }

            Cache::put($key, $index, now()->addHours(self::TTL_HOURS));
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /** The card stage a feature's own stage word stands for. */
    public static function normaliseStage(?string $stage): string
    {
        $stage = strtolower(trim((string) $stage));

        if ($stage === '') {
            return self::STAGE_QUEUED;
        }

        return self::STAGE_ALIASES[$stage] ?? self::STAGE_RUNNING;
    }

    /** Is this task still something to wait for? */
    public static function isActive(array $task): bool
    {
        return in_array((string) ($task['stage'] ?? ''), self::ACTIVE_STAGES, true);
    }

    /**
     * What the queue window shows a viewer: all of their own tasks, plus the
     * still-unfinished tasks of the users they are responsible for — an admin
     * sees everyone, a reseller their own customers, anyone else only
     * themselves (the convention of Website::scopeVisibleTo).
     *
     * Another user's finished task is left out: only work that is still worth
     * waiting for is another user's business.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleTo(?User $viewer, ?string $type = null): array
    {
        if (! $viewer instanceof User) {
            return [];
        }

        $own = self::forUser((int) $viewer->id, $type);

        $others = [];
        foreach (self::others($viewer, $type) as $task) {
            if (self::isActive($task)) {
                $others[] = $task;
            }
        }

        $tasks = [...$own, ...$others];
        usort($tasks, fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        return self::withUserNames($tasks, (int) $viewer->id);
    }

    /**
     * The tasks of other users this viewer may see, from the cross-user index.
     *
     * @return list<array<string, mixed>>
     */
    private static function others(User $viewer, ?string $type): array
    {
        $scope = self::visibleUserIds($viewer);

        if ($scope === []) {
            return [];
        }

        $index = self::readIndex(self::ALL_INDEX_KEY);
        $live = [];
        $tasks = [];

        foreach ($index as $taskId) {
            $task = self::get($taskId);
            if ($task === null) {
                continue;
            }
            $live[] = $taskId;

            $userId = (int) ($task['user_id'] ?? 0);
            if ($userId === (int) $viewer->id) {
                continue; // already covered by the viewer's own index
            }
            if ($scope !== ['*'] && ! in_array($userId, $scope, true)) {
                continue;
            }
            if ($type !== null && (string) ($task['type'] ?? '') !== $type) {
                continue;
            }

            $tasks[] = $task;
        }

        if ($live !== $index) {
            Cache::put(self::ALL_INDEX_KEY, $live, now()->addHours(self::TTL_HOURS));
        }

        return $tasks;
    }

    /**
     * Whose tasks the viewer may see besides their own: ['*'] for everyone
     * (admin), the ids of their customers for a reseller, none for anyone else.
     *
     * @return list<int|string>
     */
    private static function visibleUserIds(User $viewer): array
    {
        if ($viewer->hasRole('admin')) {
            return ['*'];
        }

        if ($viewer->hasRole('reseller')) {
            return User::query()->where('reseller_id', $viewer->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return [];
    }

    /**
     * Add who queued each task, so a card can say whose work it is.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private static function withUserNames(array $tasks, int $viewerId): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(
            fn (array $task): int => (int) ($task['user_id'] ?? 0),
            $tasks,
        ))));

        $names = $userIds === []
            ? []
            : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all();

        return array_map(function (array $task) use ($names, $viewerId): array {
            $userId = (int) ($task['user_id'] ?? 0);

            return [
                ...$task,
                'user_name' => (string) ($names[$userId] ?? ''),
                'own' => $userId === $viewerId,
            ];
        }, $tasks);
    }
}
