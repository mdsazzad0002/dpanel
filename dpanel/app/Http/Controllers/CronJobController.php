<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use App\Support\BackupSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    public function __construct(
        private readonly CronSystemService $cronSystem,
        private readonly BackupSettings $backupSettings,
    )
    {
    }

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->findWebsite($id, $request);
        abort_if($website === null, 404);

        $jobs = CronJob::query()
            ->where('website_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CronJob $job): array => [
                'id' => (string) $job->id,
                'website_id' => (string) $job->website_id,
                'domain' => (string) ($job->domain ?? ''),
                'name' => (string) $job->name,
                'expression' => (string) $job->expression,
                'command' => (string) $job->command,
                'status' => (string) $job->status,
                'description' => (string) ($job->description ?? ''),
                'created_at' => $job->created_at?->toIso8601String(),
                'updated_at' => $job->updated_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Websites/CronJobs', [
            'website' => [
                'id' => $website->id,
                'domain' => $website->domain ?? '',
            ],
            'cronJobs' => $jobs,
        ]);
    }

    public function store(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findWebsite($id, $request);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);

        $job = CronJob::query()->create([
            'id' => (string) str()->uuid(),
            'website_id' => $id,
            'domain' => (string) ($website->domain ?? ''),
            'name' => trim((string) $validated['name']),
            'expression' => trim((string) $validated['expression']),
            'command' => trim((string) $validated['command']),
            'status' => (string) $validated['status'],
            'description' => trim((string) ($validated['description'] ?? '')),
        ]);

        try {
            $this->cronSystem->sync($job, $website);
        } catch (\Throwable $e) {
            $job->delete();

            return back()->withInput()->with('error', 'Cron job was not created: '.$e->getMessage());
        }

        return redirect()->route('websites.cronjobs.index', ['token' => $token, 'id' => $id])->with('success', 'Cron job created in database and system scheduler.');
    }

    public function update(Request $request, string $token, string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id, $request);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);

        $job = CronJob::query()
            ->where('id', $jobId)
            ->where('website_id', $id)
            ->first();

        if ($job === null) {
            return redirect()->route('websites.cronjobs.index', ['token' => $token, 'id' => $id])->with('error', 'Cron job not found.');
        }

        $original = $job->only(['name', 'expression', 'command', 'status', 'description']);
        $job->update([
            'name' => trim((string) $validated['name']),
            'expression' => trim((string) $validated['expression']),
            'command' => trim((string) $validated['command']),
            'status' => (string) $validated['status'],
            'description' => trim((string) ($validated['description'] ?? '')),
        ]);

        try {
            $this->cronSystem->sync($job->fresh(), $website);
        } catch (\Throwable $e) {
            $job->update($original);
            try {
                $this->cronSystem->sync($job->fresh(), $website);
            } catch (\Throwable) {
                // Preserve the original database state even if system rollback also fails.
            }

            return back()->withInput()->with('error', 'Cron job update failed: '.$e->getMessage());
        }

        if ((string) $job->id === 'trash-backups-prune') {
            $this->syncTrashRetentionSettings($job->fresh());
        }

        return redirect()->route('websites.cronjobs.index', ['token' => $token, 'id' => $id])->with('success', 'Cron job updated in database and system scheduler.');
    }

    public function destroy(Request $request, string $token, string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id, $request);
        abort_if($website === null, 404);

        $job = CronJob::query()
            ->where('id', $jobId)
            ->where('website_id', $id)
            ->first();

        if ($job === null) {
            return redirect()->route('websites.cronjobs.index', ['token' => $token, 'id' => $id])->with('error', 'Cron job not found.');
        }

        try {
            $this->cronSystem->delete((string) $job->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Cron job was not deleted: '.$e->getMessage());
        }
        $job->delete();
        if ((string) $job->id === 'trash-backups-prune') {
            $settings = $this->backupSettings->read();
            $settings['trash_retention_enabled'] = false;
            $this->backupSettings->write($settings);
        }

        return redirect()->route('websites.cronjobs.index', ['token' => $token, 'id' => $id])->with('success', 'Cron job deleted from database and system scheduler.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expression' => ['required', 'string', 'max:120', 'regex:/^(\S+\s+){4}\S+$/'],
            'command' => ['required', 'string', 'max:2000'],
            'status' => ['required', 'in:active,disabled'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function findWebsite(string $id, Request $request): ?Website
    {
        return Website::query()->visibleTo($request->user())->find($id);
    }

    private function syncTrashRetentionSettings(CronJob $job): void
    {
        $settings = $this->backupSettings->read();
        $parts = preg_split('/\s+/', trim((string) $job->expression)) ?: [];
        if (count($parts) === 5 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            $settings['trash_retention_time'] = sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0]);
        }
        if (preg_match('/--days=(\d+)/', (string) $job->command, $matches) === 1) {
            $settings['trash_retention_days'] = max(1, (int) $matches[1]);
        }
        $settings['trash_retention_enabled'] = (string) $job->status === 'active';
        $this->backupSettings->write($settings);
    }
}
