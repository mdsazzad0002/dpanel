<?php

namespace App\Http\Controllers;

use App\Jobs\RestoreResticItemJob;
use App\Jobs\RunResticBackupsJob;
use App\Jobs\RunResticRestoreTestJob;
use App\Models\Mailbox;
use App\Models\ResticRestoreTest;
use App\Models\ResticSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Services\PathService;
use App\Support\ResticSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Backups → Incremental (backlog W6). Admins set up the restic repository
 * and its schedule; everyone with backups sees the restore points of their
 * own websites and can take one now.
 */
class ResticBackupController extends Controller
{
    public function __construct(
        private readonly ResticSettings $settings,
        private readonly ResticBackupService $restic,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $admin = $this->isAdmin($user);
        $state = $this->settings->read();
        $websites = ResticBackupService::websites()->visibleTo($user)->get(['id', 'domain']);
        $points = ResticSnapshot::query()->whereIn('website_id', $websites->pluck('id'))->orderByDesc('taken_at')->get()->groupBy('website_id');

        return Inertia::render('Backups/Incremental/Index', [
            'isAdmin' => $admin,
            'configured' => $this->settings->configured(),
            'keyAtRisk' => $admin && $this->settings->keyAtRisk(),
            // Shown once, right after a new repository was made.
            'newKey' => $admin ? session()->get('restic_new_key') : null,
            'setup' => $admin ? [
                'repository' => $state['repository'],
                'credentials' => array_keys($state['env']),
                'enabled' => $state['enabled'],
                'schedule_time' => $state['schedule_time'],
                'keep_daily' => $state['keep_daily'],
                'keep_weekly' => $state['keep_weekly'],
                'keep_monthly' => $state['keep_monthly'],
                'last_run_at' => $state['last_run_at'],
                'last_run_status' => $state['last_run_status'],
                'last_run_message' => $state['last_run_message'],
            ] : null,
            'restoreTest' => $admin ? $this->restoreTestReport($state) : null,
            'websites' => $websites->map(fn (Website $website) => [
                'id' => (string) $website->id,
                'domain' => (string) $website->domain,
                'restore_points' => ($points->get($website->id) ?? collect())->take(60)->map(fn (ResticSnapshot $point) => [
                    'id' => $point->snapshot_id,
                    'short_id' => substr($point->snapshot_id, 0, 8),
                    'taken_at' => $point->taken_at?->toIso8601String(),
                    'files_new' => $point->files_new,
                    'data_added' => $point->data_added,
                    'total_bytes' => $point->total_bytes,
                    'warning' => $point->warning,
                ])->values(),
            ])->values(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(ResticBackupService::KINDS)],
            'path' => ['nullable', 'string', 'max:500'],
            'host' => ['nullable', 'string', 'max:255', 'required_if:kind,sftp'],
            'user' => ['nullable', 'string', 'max:64', 'required_if:kind,sftp'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255', 'required_if:kind,s3'],
            'remote' => ['nullable', 'string', 'max:100', 'required_if:kind,rclone'],
            'access_key' => ['nullable', 'string', 'max:255', 'required_if:kind,s3'],
            'secret_key' => ['nullable', 'string', 'max:255', 'required_if:kind,s3'],
            'region' => ['nullable', 'string', 'max:64'],
            // Given: an existing repository. Empty: a new one with a new key.
            'password' => ['nullable', 'string', 'max:1024'],
        ]);
        foreach (['host', 'user', 'bucket', 'remote'] as $field) {
            if (isset($validated[$field]) && ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $validated[$field])) {
                return back()->withErrors([$field => 'Only letters, digits, dots, dashes and underscores.']);
            }
        }
        $env = $validated['kind'] === 's3' ? array_filter([
            'AWS_ACCESS_KEY_ID' => (string) $validated['access_key'],
            'AWS_SECRET_ACCESS_KEY' => (string) $validated['secret_key'],
            'AWS_DEFAULT_REGION' => (string) ($validated['region'] ?? ''),
        ]) : [];

        try {
            $result = $this->restic->connect(ResticBackupService::location($validated['kind'], $validated), $env, $validated['password'] ?? null);
            if (! $result['created']) {
                $this->restic->sync();
            }
        } catch (Throwable $e) {
            return back()->with('error', 'The repository could not be set up: '.$e->getMessage());
        }

        $redirect = redirect()->route('backups.incremental');
        if ($result['generated_password'] !== null) {
            $redirect->with('restic_new_key', $result['generated_password']);
        }

        return $redirect->with('success', $result['created'] ? 'Repository created. Save its key now.' : 'Connected to the repository.');
    }

    /** The key as a file to keep off this server. */
    public function downloadKey(Request $request): StreamedResponse
    {
        $this->adminOnly($request);
        $state = $this->settings->read();
        abort_if($state['password'] === '', 404);
        $text = "dPanel incremental backup key\n\nRepository: {$state['repository']}\nPassword: {$state['password']}\n\n"
            ."Without this password the backups cannot be read by anyone. Keep it off this server.\n"
            ."Restore by hand: RESTIC_REPOSITORY='{$state['repository']}' RESTIC_PASSWORD='<password>' restic snapshots\n";

        return response()->streamDownload(fn () => print ($text), 'dpanel-backup-key.txt', ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function confirmKey(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        $request->validate(['saved' => ['accepted']]);
        $this->settings->write(['key_confirmed_at' => now()->toIso8601String()]);

        return back()->with('success', 'Noted. Keep that copy safe: it is the only way back into the backups.');
    }

    public function updateSchedule(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule_time' => ['required', 'date_format:H:i'],
            'keep_daily' => ['required', 'integer', 'min:0', 'max:366'],
            'keep_weekly' => ['required', 'integer', 'min:0', 'max:520'],
            'keep_monthly' => ['required', 'integer', 'min:0', 'max:240'],
        ]);
        if ($validated['keep_daily'] + $validated['keep_weekly'] + $validated['keep_monthly'] === 0) {
            return back()->withErrors(['keep_daily' => 'Keep at least one restore point.']);
        }
        if ($validated['enabled'] && ! $this->settings->configured()) {
            return back()->withErrors(['enabled' => 'Set up the repository first.']);
        }
        $this->settings->write($validated);

        return back()->with('success', 'Schedule saved.');
    }

    /** A restore point now, for one website or, for an admin, all of them. */
    public function runNow(Request $request): RedirectResponse
    {
        $validated = $request->validate(['website_id' => ['nullable', 'string', 'max:64']]);
        if (! $this->settings->configured()) {
            return back()->with('error', 'Incremental backups are not set up on this server.');
        }
        $user = $request->user();
        if (empty($validated['website_id'])) {
            $this->adminOnly($request);
            $ids = null;
        } else {
            $website = ResticBackupService::websites()->visibleTo($user)->findOrFail($validated['website_id']);
            $ids = [(string) $website->id];
        }

        if (ResticRestoreTest::running() !== null) {
            return back()->with('error', 'A restore test is reading the repository. Back up again when it has finished.');
        }

        RunResticBackupsJob::dispatch($ids);

        return back()->with('success', 'Backup started. The restore point appears here when it is done.');
    }

    /** W8: the restore test now, outside the weekly schedule. */
    public function testNow(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        if (! $this->settings->configured()) {
            return back()->with('error', 'Incremental backups are not set up on this server.');
        }
        $state = $this->settings->read();
        $backingUp = $state['last_run_status'] === 'running' && $state['last_run_at'] !== null && now()->subDay()->lt($state['last_run_at']);
        if ($backingUp || ResticRestoreTest::running() !== null) {
            return back()->with('error', $backingUp ? 'A backup is running. Test the restore points when it has finished.' : 'A restore test is already running.');
        }

        RunResticRestoreTestJob::dispatch('manual');

        return back()->with('success', 'Restore test started. The report appears here when it is done.');
    }

    public function updateRestoreTest(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'restore_test_enabled' => ['required', 'boolean'],
            'restore_test_day' => ['required', 'integer', 'min:0', 'max:6'],
            'restore_test_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'restore_test_sample' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $this->settings->write($validated);

        return back()->with('success', 'Restore test settings saved.');
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function restoreTestReport(array $state): array
    {
        $lastPassed = ResticRestoreTest::query()->where('status', 'passed')->latest('started_at')->value('started_at');

        return [
            'enabled' => $state['restore_test_enabled'],
            'day' => $state['restore_test_day'],
            'percent' => $state['restore_test_percent'],
            'sample' => $state['restore_test_sample'],
            // The test rides on the scheduled backup; without it nothing runs.
            'scheduled' => $state['enabled'] && $state['restore_test_enabled'],
            'last_passed_at' => $lastPassed ? \Illuminate\Support\Carbon::parse($lastPassed)->toIso8601String() : null,
            'runs' => ResticRestoreTest::query()->latest('started_at')->limit(10)->get()->map(fn (ResticRestoreTest $test) => [
                'id' => $test->id,
                'trigger' => $test->trigger,
                'status' => $test->status,
                'started_at' => $test->started_at?->toIso8601String(),
                'finished_at' => $test->finished_at?->toIso8601String(),
                'repository_healthy' => $test->repository_healthy,
                'repository_message' => $test->repository_message,
                'read_data_percent' => $test->read_data_percent,
                'websites_tested' => $test->websites_tested,
                'websites_failed' => $test->websites_failed,
                'message' => $test->message,
                'results' => array_values((array) $test->results),
            ])->values(),
        ];
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        try {
            $count = $this->restic->sync();
        } catch (Throwable $e) {
            return back()->with('error', 'Could not read the repository: '.$e->getMessage());
        }

        return back()->with('success', "The repository holds {$count} restore point(s).");
    }

    /** W7: one restore point, to pick what comes back. */
    public function showPoint(Request $request, string $token, string $website, string $snapshot): Response
    {
        [$site, $point] = $this->point($request, $website, $snapshot);

        return Inertia::render('Backups/Incremental/Restore', [
            'website' => ['id' => (string) $site->id, 'domain' => (string) $site->domain, 'home' => PathService::siteHome((string) $site->site_owner)],
            'point' => ['id' => $point->snapshot_id, 'short_id' => substr($point->snapshot_id, 0, 8), 'taken_at' => $point->taken_at?->toIso8601String()],
            'databases' => \App\Models\DatabaseRequest::query()->where('domain', $site->domain)->where('status', 'active')->orderBy('database_name')->pluck('database_name')->values(),
            'mailboxes' => Mailbox::query()->where('domain', $site->domain)->orderBy('email')->get(['email', 'mail_home'])
                ->filter(fn (Mailbox $mailbox) => (string) $mailbox->mail_home !== '')
                ->map(fn (Mailbox $mailbox) => ['email' => (string) $mailbox->email, 'path' => (string) $mailbox->mail_home])->values(),
        ]);
    }

    public function browse(Request $request, string $token, string $website, string $snapshot): JsonResponse
    {
        [$site, $point] = $this->point($request, $website, $snapshot);
        $validated = $request->validate(['path' => ['required', 'string', 'max:4096']]);
        try {
            return response()->json($this->restic->browse($site, $point->snapshot_id, $validated['path']));
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function restore(Request $request, string $token, string $website, string $snapshot): JsonResponse
    {
        [$site, $point] = $this->point($request, $website, $snapshot);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['files', 'database', 'mailbox'])],
            'path' => ['required_unless:kind,database', 'nullable', 'string', 'max:4096'],
            'name' => ['required_if:kind,database', 'nullable', 'string', 'max:64'],
        ]);
        $item = match ($validated['kind']) {
            'database' => ['kind' => 'database', 'name' => (string) $validated['name']],
            default => ['kind' => $validated['kind'], 'path' => (string) $validated['path']],
        };
        if ($item['kind'] === 'database' && ! \App\Models\DatabaseRequest::query()->where('domain', $site->domain)->where('status', 'active')->where('database_name', $item['name'])->exists()) {
            return response()->json(['message' => 'That database is not this website\'s.'], 422);
        }
        if ($item['kind'] === 'mailbox' && ! Mailbox::query()->where('domain', $site->domain)->where('mail_home', $item['path'])->exists()) {
            return response()->json(['message' => 'That mailbox is not this website\'s.'], 422);
        }

        $restoreId = (string) Str::uuid();
        RestoreResticItemJob::record($restoreId, (int) $request->user()->id, 'queued', 'Waiting for the restore to start…');
        RestoreResticItemJob::dispatch($restoreId, (int) $request->user()->id, (string) $site->id, $point->snapshot_id, $item);

        return response()->json(['restore_id' => $restoreId, 'message' => 'Restore started.']);
    }

    public function restoreStatus(Request $request, string $token, string $restore): JsonResponse
    {
        $status = RestoreResticItemJob::status($restore);
        abort_unless($status !== null && ($status['user_id'] === (int) $request->user()->id || $this->isAdmin($request->user())), 404);

        return response()->json(collect($status)->except('user_id')->all());
    }

    /** @return array{0: Website, 1: ResticSnapshot} the website the user may see, and its restore point */
    private function point(Request $request, string $website, string $snapshot): array
    {
        abort_unless($this->settings->configured(), 404);
        $site = ResticBackupService::websites()->visibleTo($request->user())->findOrFail($website);
        $point = ResticSnapshot::query()->where('website_id', $site->id)->where('snapshot_id', $snapshot)->firstOrFail();

        return [$site, $point];
    }

    private function isAdmin(?User $user): bool
    {
        return (bool) $user?->hasRole('admin');
    }

    /** The repository's key opens every website's backup. */
    private function adminOnly(Request $request): void
    {
        abort_unless($this->isAdmin($request->user()), 403);
    }
}
