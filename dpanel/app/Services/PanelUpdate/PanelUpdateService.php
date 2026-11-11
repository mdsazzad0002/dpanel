<?php

namespace App\Services\PanelUpdate;

use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Platform\PanelUpdateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Starting dPanel updates and rollbacks, and keeping `panel_updates` in step
 * with drust's runs (backlog U6). drust holds the truth: its run folders
 * survive the panel being replaced and its database being restored.
 */
class PanelUpdateService
{
    /** A tag, branch or commit; the same rule drust applies. */
    public const REF_PATTERN = '/^(?![-.\/])(?!.*\.\.)(?!.*\/\/)(?!.*\.lock$)[A-Za-z0-9._\/-]{1,100}(?<![\/.])$/';

    public const RUN_ID_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{4}$/';

    private const FINISHED = ['succeeded', 'failed', 'rolled_back', 'interrupted'];

    private const SHOWN = 30;

    public function __construct(
        private readonly PanelUpdateClient $client,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Reads drust's runs into the table and tells admins about runs that
     * just finished.
     *
     * @return array{active: ?string, runs: Collection<int, PanelUpdate>}
     */
    public function sync(): array
    {
        $remote = $this->client->runs();
        foreach ($remote['runs'] as $run) {
            $this->store($run);
        }
        $this->notifyFinished();

        return ['active' => $remote['active'], 'runs' => $this->recent()];
    }

    /** @return Collection<int, PanelUpdate> newest first */
    public function recent(): Collection
    {
        return PanelUpdate::query()->with('user:id,name')->orderByDesc('run_id')->limit(self::SHOWN)->get();
    }

    public function update(User $user, string $ref): PanelUpdate
    {
        $this->refuseWhileRunning();

        return $this->store($this->client->start([
            'action' => 'update',
            'target_ref' => $ref,
            'repo' => (string) config('panel_update.repo'),
        ]), $user);
    }

    public function rollback(User $user, ?string $runId, bool $restoreDatabase): PanelUpdate
    {
        $this->refuseWhileRunning();
        $request = ['action' => 'rollback', 'restore_database' => $restoreDatabase];
        if ($runId !== null) {
            $request['from_run'] = $runId;
        }

        return $this->store($this->client->start($request), $user);
    }

    /** @return array{run: string, text: string, offset: int} */
    public function log(string $runId, int $offset): array
    {
        $result = $this->client->run($runId, $offset);
        if ($result['run'] !== []) {
            $this->store($result['run']);
        }

        return ['run' => $runId, 'text' => $result['log'], 'offset' => $result['offset']];
    }

    /** drust refuses a second run too; asking first gives a clearer answer. */
    private function refuseWhileRunning(): void
    {
        $state = $this->sync();
        if ($state['active'] !== null) {
            throw new PanelUpdateRefused("Update {$state['active']} is still running; wait for it to finish.", 409);
        }
    }

    /** @param  array<string, mixed>  $run  as drust reports it */
    private function store(array $run, ?User $user = null): PanelUpdate
    {
        $id = (string) ($run['id'] ?? '');
        if (preg_match(self::RUN_ID_PATTERN, $id) !== 1) {
            throw new PanelUpdateRefused('drust returned a run without a valid id.', 502);
        }
        $text = fn (string $key, int $max = 120) => ($value = trim((string) ($run[$key] ?? ''))) === '' ? null : mb_substr($value, 0, $max);
        $row = PanelUpdate::query()->firstOrNew(['run_id' => $id]);
        $row->fill([
            'action' => $text('action', 16) ?? 'update',
            'status' => $text('status', 16) ?? 'queued',
            'step' => $text('step', 32),
            'target_ref' => $text('target_ref'),
            'from_version' => $text('from_version'),
            'from_ref' => $text('from_ref'),
            'from_commit' => $text('from_commit', 64),
            'to_version' => $text('to_version'),
            'to_ref' => $text('to_ref'),
            'to_commit' => $text('to_commit', 64),
            'rollback_of' => $text('rollback_of', 32),
            'restore_database' => isset($run['restore_database']) ? in_array((string) $run['restore_database'], ['1', 'true'], true) : null,
            'has_backup' => (bool) ($run['has_backup'] ?? false),
            'message' => $text('message', 2000),
            'started_at' => self::time($run['started_at'] ?? null),
            'finished_at' => self::time($run['finished_at'] ?? null),
        ]);
        if ($user !== null && $row->user_id === null) {
            $row->user_id = $user->id;
        }
        $row->save();

        return $row;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Once per run. A run that ended more than a day ago is not news: it only
     * reappears because a rollback restored an older copy of this table.
     */
    private function notifyFinished(): void
    {
        PanelUpdate::query()
            ->whereNull('notified_at')
            ->whereIn('status', self::FINISHED)
            ->where('finished_at', '>=', now()->subDay())
            ->get()
            ->each(function (PanelUpdate $row): void {
                [$status, $title] = $this->outcome($row);
                $this->notifications->create(
                    type: 'panel_update',
                    status: $status,
                    title: $title,
                    message: $row->message,
                    createdBy: $row->user_id,
                    subject: $row,
                    data: ['run_id' => $row->run_id, 'action' => $row->action, 'status' => $row->status],
                );
                $row->forceFill(['notified_at' => now()])->save();
            });
    }

    /** @return array{0: string, 1: string} notification status and title */
    private function outcome(PanelUpdate $row): array
    {
        $version = $row->to_version ?? $row->target_ref ?? '';

        return match (true) {
            $row->status === 'succeeded' && $row->action === 'rollback' => ['completed', trim("dPanel rolled back to {$version}")],
            $row->status === 'succeeded' => ['completed', trim("dPanel updated to {$version}")],
            $row->status === 'rolled_back' => ['warning', 'dPanel update failed and was undone'],
            default => ['blocked', $row->action === 'rollback' ? 'dPanel rollback failed' : 'dPanel update failed'],
        };
    }
}
