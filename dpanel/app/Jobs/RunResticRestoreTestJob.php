<?php

namespace App\Jobs;

use App\Models\ResticRestoreTest;
use App\Models\ResticSnapshot;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Services\NotificationService;
use App\Support\ResticSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Backlog W8: the restore test. First restic's own check of the repository,
 * then, for every website, its newest restore point read back as a restore
 * would read it. A website with no restore point, or only an old one while
 * the daily backup is on, fails too: there would be nothing recent to bring
 * back. The run is kept as a report; admins are told when anything failed.
 */
class RunResticRestoreTestJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 86400;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    /** Reports kept; older ones go. */
    public const KEEP = 52;

    /** With the daily backup on, a newest restore point older than this fails. */
    public const STALE_HOURS = 48;

    public function __construct(public string $trigger = 'schedule')
    {
        $this->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return 'restic-restore-test';
    }

    public function handle(ResticBackupService $restic, ResticSettings $settings): void
    {
        $state = $settings->read();
        $test = ResticRestoreTest::query()->create([
            'trigger' => $this->trigger,
            'status' => 'running',
            'started_at' => now(),
            'read_data_percent' => $state['restore_test_percent'],
        ]);

        try {
            $repository = $restic->check($state['restore_test_percent']);
        } catch (Throwable $exception) {
            $repository = ['healthy' => false, 'message' => $exception->getMessage()];
        }
        $test->update(['repository_healthy' => $repository['healthy'], 'repository_message' => mb_substr($repository['message'], 0, 4000)]);

        $results = [];
        foreach (ResticBackupService::websites()->get() as $website) {
            $results[] = $this->testWebsite($restic, $website, $state);
            $test->update(['results' => $results, 'websites_tested' => count($results)]);
        }

        $failed = array_values(array_filter($results, fn (array $result) => ! $result['ok']));
        $passed = $repository['healthy'] && $failed === [];
        $message = $passed
            ? 'Every restore point read back whole ('.count($results).' website(s)).'
            : $this->failureSummary($repository, $failed);
        $test->update([
            'status' => $passed ? 'passed' : 'failed',
            'finished_at' => now(),
            'websites_failed' => count($failed),
            'message' => mb_substr($message, 0, 2000),
        ]);
        if (! $passed) {
            app(NotificationService::class)->create(type: 'backup', status: 'failed', title: 'Restore test failed', message: mb_substr($message, 0, 1000));
        }

        $keep = ResticRestoreTest::query()->latest('started_at')->limit(self::KEEP)->pluck('id');
        ResticRestoreTest::query()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function testWebsite(ResticBackupService $restic, Website $website, array $state): array
    {
        $result = ['website_id' => (string) $website->id, 'domain' => (string) $website->domain, 'ok' => false, 'snapshot_id' => null, 'taken_at' => null, 'error' => null];
        $point = ResticSnapshot::query()->where('website_id', $website->id)->latest('taken_at')->first();
        if ($point === null) {
            return array_merge($result, ['problem' => 'missing', 'error' => 'There is no restore point of this website.']);
        }
        $result['snapshot_id'] = $point->snapshot_id;
        $result['taken_at'] = $point->taken_at?->toIso8601String();

        try {
            $report = $restic->verify($website, $point->snapshot_id, $state['restore_test_sample']);
        } catch (Throwable $exception) {
            return array_merge($result, ['problem' => 'unreadable', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
        $sampled = array_values((array) ($report['sampled'] ?? []));
        $databases = array_values((array) ($report['databases'] ?? []));
        $broken = array_merge(
            array_map(fn ($item) => ($item['path'] ?? '?').': '.($item['error'] ?? 'failed'), array_filter($sampled, fn ($item) => empty($item['ok']))),
            array_map(fn ($item) => 'database '.($item['name'] ?? '?').': '.($item['error'] ?? 'failed'), array_filter($databases, fn ($item) => empty($item['ok']))),
        );
        $result = array_merge($result, [
            'ok' => (bool) ($report['ok'] ?? false) && $broken === [],
            'files' => (int) ($report['files'] ?? 0),
            'bytes' => (int) ($report['bytes'] ?? 0),
            'files_read' => count($sampled),
            'too_large_to_sample' => (int) ($report['too_large_to_sample'] ?? 0),
            'databases' => array_map(fn ($item) => ['name' => (string) ($item['name'] ?? ''), 'size' => (int) ($item['size'] ?? 0), 'ok' => (bool) ($item['ok'] ?? false)], $databases),
            'problem' => $broken === [] ? null : 'damaged',
            'error' => $broken === [] ? null : mb_substr(implode('; ', array_slice($broken, 0, 5)), 0, 1000),
        ]);

        $stale = $state['enabled'] && $point->taken_at !== null && $point->taken_at->lt(now()->subHours(self::STALE_HOURS));
        if ($result['ok'] && $stale) {
            $result['ok'] = false;
            $result['problem'] = 'stale';
            $result['error'] = 'The newest restore point is from '.$point->taken_at->toDayDateTimeString().'; the daily backup has not added one since.';
        }

        return $result;
    }

    /**
     * @param  array{healthy: bool, message: string}  $repository
     * @param  list<array<string, mixed>>  $failed
     */
    private function failureSummary(array $repository, array $failed): string
    {
        $lines = [];
        if (! $repository['healthy']) {
            $lines[] = 'repository check: '.$repository['message'];
        }
        foreach (array_slice($failed, 0, 10) as $result) {
            $lines[] = $result['domain'].': '.$result['error'];
        }

        return 'The restore test found problems. '.implode('; ', $lines);
    }
}
