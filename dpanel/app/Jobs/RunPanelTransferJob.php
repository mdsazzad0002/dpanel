<?php

namespace App\Jobs;

use App\Models\PanelTransfer;
use App\Models\User;
use App\Models\Website;
use App\Services\NotificationService;
use App\Services\PanelLink\PanelLinkClient;
use App\Services\PanelLink\PanelPackageBuilder;
use App\Services\PanelLink\PanelPackageImporter;
use App\Services\PanelLink\PanelPackageStore;
use App\Services\SitePush\FileSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Runs one PanelTransfer from this panel; the other panel only answers.
 *
 * pull: ask the other panel for a package (export) → wait while it builds
 *       → download it in chunks → import it here.
 * push: build the package here → upload it in chunks → ask the other panel
 *       to import it → wait for the result.
 *
 * "files" compares both sides' file lists first and moves only what differs,
 * so running it again later is a sync. Progress is saved on the transfer
 * after every chunk; each run works for at most RUN_BUDGET seconds and then
 * queues a fresh copy of itself, and waits are a delayed re-queue rather than
 * a sleep, so a panel linked to itself can't deadlock its heavy worker.
 */
class RunPanelTransferJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    private const RUN_BUDGET = 2400;

    private const POLL_SECONDS = 5;

    /** The other side's build or import may wait its turn and then take an hour. */
    private const REMOTE_WAIT_LIMIT = 4 * 3600;

    private int $startedAt = 0;

    public function __construct(public string $transferId)
    {
        $this->onQueue('heavy');
    }

    public function handle(PanelPackageStore $store, NotificationService $notifications): void
    {
        $this->startedAt = time();
        $transfer = PanelTransfer::query()->with('connection')->find($this->transferId);
        if (! $transfer || ! $transfer->isActive()) {
            return;
        }
        $actor = User::query()->find($transfer->user_id);
        if (! $transfer->connection || ! $actor) {
            $this->fail($transfer, 'The connection or the user who started this transfer no longer exists.');

            return;
        }
        if ($transfer->status === 'queued') {
            $transfer->update(['status' => 'running']);
        }
        $client = PanelLinkClient::for($transfer->connection);
        if ($this->waitingForParent($transfer)) {
            $this->progress($transfer, 0, 'Waiting for '.($transfer->options['site']['parent_domain'] ?? 'the main website').' to be copied first');
            $this->later($transfer, (array) $transfer->state, 15);

            return;
        }

        try {
            $done = $transfer->direction === 'pull'
                ? $this->pull($transfer, $client, $store, $actor)
                : $this->push($transfer, $client, $store, $actor);
        } catch (PanelTransferCancelled) {
            File::deleteDirectory($store->directory($transfer->id));

            return;
        } catch (Throwable $e) {
            $this->fail($transfer, $e->getMessage());
            $notifications->notifyUser(actor: $actor, type: 'clone_website', status: 'blocked', title: 'dPanel migration failed: '.$this->label($transfer), message: $e->getMessage());

            return;
        }

        if ($done) {
            $notifications->notifyUser(actor: $actor, type: 'clone_website', status: 'completed', title: 'dPanel migration finished: '.$this->label($transfer), message: (string) $transfer->message);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $transfer = PanelTransfer::query()->find($this->transferId);
        if ($transfer) {
            $this->fail($transfer, 'Transfer stopped: '.($exception?->getMessage() ?: 'worker timeout or crash'));
        }
    }

    /** @return bool true once the transfer is finished */
    private function pull(PanelTransfer $transfer, PanelLinkClient $client, PanelPackageStore $store, User $actor): bool
    {
        $state = (array) $transfer->state;
        $options = (array) $transfer->options;

        if (empty($state['export_id'])) {
            $paths = [];
            if ($transfer->kind === 'files') {
                $local = app(PanelPackageImporter::class)->existingWebsite($actor, (string) $transfer->domain);
                $this->progress($transfer, 3, 'Listing the files on both panels (.gitignore applies, .git is skipped)');
                $files = app(FileSyncService::class);
                $sync = $files->diff($client->manifest((string) $transfer->domain)['files'] ?? [], $files->manifest($local)['files'], (bool) ($options['delete_missing'] ?? false));
                if ($sync['upload'] === [] && $sync['delete'] === []) {
                    return $this->complete($transfer, "{$transfer->domain} is already the same on both panels ({$sync['unchanged']} files).");
                }
                $paths = $sync['upload'];
                $state += ['delete' => $sync['delete'], 'unchanged' => $sync['unchanged']];
                if ($paths === []) {
                    // Only removals: nothing to download.
                    $state['package_path'] = null;
                    $this->save($transfer, $state);

                    return $this->importPulled($transfer, $store, $actor, $state);
                }
            }
            $this->progress($transfer, 5, $transfer->kind === 'panel' ? 'The other panel is making a full backup' : 'The other panel is packaging '.$transfer->domain);
            $export = $client->startExport($transfer->kind, $transfer->kind === 'panel' ? null : $transfer->domain, $paths);
            $state += ['export_id' => (string) $export['id'], 'waiting_since' => time()];
            $this->save($transfer, $state);
        }

        if (empty($state['total'])) {
            $export = $client->export($state['export_id']);
            if (($export['status'] ?? '') === 'failed') {
                throw new RuntimeException('The other panel could not build the package: '.($export['message'] ?? 'unknown error'));
            }
            if (($export['status'] ?? '') !== 'ready') {
                $this->guardWait($state, 'build the package');

                return $this->later($transfer, $state, self::POLL_SECONDS);
            }
            File::ensureDirectoryExists($store->directory($transfer->id), 0750);
            $state = array_merge($state, [
                'size' => (int) $export['size'], 'total' => (int) $export['total'], 'chunk_size' => (int) $export['chunk_size'],
                'sha256' => (string) $export['sha256'], 'name' => (string) ($export['name'] ?? ''), 'next_index' => 0,
                'package_path' => $store->packagePath($transfer->id, PanelPackageBuilder::extension($transfer->kind)),
                'warning' => (string) ($export['warning'] ?? ''),
            ]);
            File::put($state['package_path'], '');
            $this->save($transfer, $state);
        }

        while ($state['next_index'] < $state['total']) {
            if ($this->outOfTime()) {
                return $this->later($transfer, $state);
            }
            $index = (int) $state['next_index'];
            $bytes = $client->exportChunk($state['export_id'], $index);
            $handle = fopen($state['package_path'], 'c');
            ftruncate($handle, $index * $state['chunk_size']);
            fseek($handle, $index * $state['chunk_size']);
            fwrite($handle, $bytes);
            fclose($handle);
            $state['next_index'] = $index + 1;
            $this->save($transfer, $state);
            $this->progress($transfer, 10 + (int) floor(70 * $state['next_index'] / $state['total']), sprintf('Downloading %s / %s', $this->bytes(min($state['size'], $state['next_index'] * $state['chunk_size'])), $this->bytes($state['size'])));
        }

        if (empty($state['verified'])) {
            if (filesize($state['package_path']) !== $state['size'] || hash_file('sha256', $state['package_path']) !== $state['sha256']) {
                File::delete($state['package_path']);
                throw new RuntimeException('The downloaded package does not match the other panel\'s — start the transfer again.');
            }
            $client->deleteExport($state['export_id']);
            $state['verified'] = true;
            $this->save($transfer, $state);
            if ($this->outOfTime()) {
                return $this->later($transfer, $state);
            }
        }

        return $this->importPulled($transfer, $store, $actor, $state);
    }

    private function importPulled(PanelTransfer $transfer, PanelPackageStore $store, User $actor, array $state): bool
    {
        $options = (array) $transfer->options;
        $labels = ['creating' => 'Creating the website here', 'extracting' => 'Unpacking', 'backing_up_target' => 'Moving the current site to .trash', 'restoring' => 'Restoring files and database'];
        $this->progress($transfer, 85, $transfer->kind === 'files' ? 'Writing the changed files' : 'Importing');
        try {
            if ($transfer->kind === 'files' && empty($state['package_path'])) {
                $website = app(PanelPackageImporter::class)->existingWebsite($actor, (string) $transfer->domain);
                $result = app(FileSyncService::class)->apply($website, $this->emptyArchive($store, $transfer), (array) ($state['delete'] ?? []));
                $result['message'] = "{$website->domain}: {$result['deleted']} files removed.";
            } else {
                $result = app(PanelPackageImporter::class)->import($actor, $transfer->kind, (string) $state['package_path'], (array) ($options['site'] ?? []) + ['domain' => $transfer->domain, 'archive_name' => $state['name'] ?? ''], [
                    'owner_id' => $options['owner_id'] ?? null,
                    'manage_dns' => $options['manage_dns'] ?? false,
                    'delete' => $state['delete'] ?? [],
                ], function (string $stage) use ($transfer, $labels): void {
                    $this->progress($transfer, 90, $labels[$stage] ?? 'Importing');
                });
            }
        } finally {
            File::deleteDirectory($store->directory($transfer->id));
        }

        return $this->complete($transfer, trim($result['message'].' '.($state['warning'] ?? '')));
    }

    /** @return bool true once the transfer is finished */
    private function push(PanelTransfer $transfer, PanelLinkClient $client, PanelPackageStore $store, User $actor): bool
    {
        $state = (array) $transfer->state;
        $options = (array) $transfer->options;

        if (empty($state['upload_id'])) {
            $website = null;
            if ($transfer->kind !== 'panel') {
                $website = Website::query()->visibleTo($actor)->where('domain', (string) $transfer->domain)->first();
                if (! $website instanceof Website) {
                    throw new RuntimeException("{$transfer->domain} is no longer on this panel.");
                }
            }
            File::ensureDirectoryExists($store->directory($transfer->id), 0750);
            $packagePath = $store->packagePath($transfer->id, PanelPackageBuilder::extension($transfer->kind));
            $paths = [];
            $delete = [];
            if ($transfer->kind === 'files') {
                $this->progress($transfer, 3, 'Listing the files on both panels (.gitignore applies, .git is skipped)');
                $files = app(FileSyncService::class);
                $sync = $files->diff($files->manifest($website)['files'], $client->manifest((string) $transfer->domain)['files'] ?? [], (bool) ($options['delete_missing'] ?? false));
                if ($sync['upload'] === [] && $sync['delete'] === []) {
                    File::deleteDirectory($store->directory($transfer->id));

                    return $this->complete($transfer, "{$transfer->domain} is already the same on both panels ({$sync['unchanged']} files).");
                }
                $paths = $sync['upload'];
                $delete = $sync['delete'];
            }
            $this->progress($transfer, 8, match ($transfer->kind) {
                'panel' => 'Making a full backup of this panel',
                'files' => sprintf('Packaging %d changed files', count($paths)),
                default => 'Packaging '.$transfer->domain.' (files and database)',
            });
            $built = $paths === [] && $transfer->kind === 'files'
                ? ['path' => $this->emptyArchive($store, $transfer), 'name' => '', 'warning' => '']
                : app(PanelPackageBuilder::class)->build($actor, $transfer->kind, $website, $paths, $packagePath);
            $size = (int) filesize($built['path']);
            $site = $transfer->kind === 'panel'
                ? ['archive_name' => $built['name']]
                : $this->siteInfo($website);
            $upload = $client->startUpload($transfer->kind, $size, $store->chunkCount($size), (string) hash_file('sha256', $built['path']), $site, array_filter([
                'delete' => $delete ?: null,
                'manage_dns' => (bool) ($options['manage_dns'] ?? false),
            ], fn ($value) => $value !== null));
            $state = [
                'upload_id' => (string) $upload['id'], 'package_path' => $built['path'], 'size' => $size,
                'total' => $store->chunkCount($size), 'chunk_size' => PanelPackageStore::CHUNK_SIZE, 'next_index' => 0,
                'warning' => (string) ($built['warning'] ?? ''),
            ];
            $this->save($transfer, $state);
        }

        while ($state['next_index'] < $state['total']) {
            if ($this->outOfTime()) {
                return $this->later($transfer, $state);
            }
            $index = (int) $state['next_index'];
            $client->uploadChunk($state['upload_id'], $index, app(PanelPackageStore::class)->readChunk(['path' => $state['package_path']], $index));
            $state['next_index'] = $index + 1;
            $this->save($transfer, $state);
            $this->progress($transfer, 10 + (int) floor(70 * $state['next_index'] / $state['total']), sprintf('Uploading %s / %s', $this->bytes(min($state['size'], $state['next_index'] * $state['chunk_size'])), $this->bytes($state['size'])));
        }

        if (empty($state['completed'])) {
            $this->progress($transfer, 82, 'The other panel is checking the upload');
            $client->completeUpload($state['upload_id']);
            File::deleteDirectory($store->directory($transfer->id));
            $state += ['completed' => true, 'waiting_since' => time()];
            $this->save($transfer, $state);
        }

        $remote = $client->upload($state['upload_id']);
        $status = (string) ($remote['status'] ?? '');
        if ($status === 'failed') {
            throw new RuntimeException('The other panel could not import it: '.($remote['message'] ?? 'unknown error'));
        }
        if ($status !== 'completed') {
            $this->guardWait($state, 'import it');
            $labels = ['queued' => 'Waiting for the other panel\'s worker', 'importing' => 'The other panel is importing', 'creating' => 'The other panel is creating the website', 'extracting' => 'The other panel is unpacking', 'backing_up_target' => 'The other panel is moving the current site to .trash', 'restoring' => 'The other panel is restoring files and database'];
            $this->progress($transfer, 88, $labels[(string) ($remote['stage'] ?? $status)] ?? 'The other panel is working');

            return $this->later($transfer, $state, self::POLL_SECONDS);
        }

        return $this->complete($transfer, trim((string) ($remote['message'] ?? 'Done.').' '.($state['warning'] ?? '')));
    }

    /** @return array<string, mixed> what the other panel needs to create the site */
    private function siteInfo(Website $website): array
    {
        $parent = $website->parent_id ? Website::query()->find($website->parent_id) : null;
        $owner = $website->assigned_user_id ? User::query()->find($website->assigned_user_id) : null;

        return array_filter([
            'domain' => (string) $website->domain,
            'type' => in_array((string) $website->type, ['subdomain', 'sub'], true) ? 'sub' : 'main',
            'parent_domain' => $parent?->domain,
            'php_version' => $website->php_version ? (string) $website->php_version : null,
            'owner_email' => $owner?->email,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** A files sync that only removes still sends (or applies) an archive, an empty one. */
    private function emptyArchive(PanelPackageStore $store, PanelTransfer $transfer): string
    {
        File::ensureDirectoryExists($store->directory($transfer->id), 0750);
        $path = $store->directory($transfer->id).'/empty.tar.gz';
        // A gzip of a tar holding only its two zero end blocks.
        File::put($path, (string) gzencode(str_repeat("\0", 1024)));

        return $path;
    }

    private function guardWait(array $state, string $what): void
    {
        if (time() - (int) ($state['waiting_since'] ?? time()) > self::REMOTE_WAIT_LIMIT) {
            throw new RuntimeException('The other panel did not '.$what.' within '.intdiv(self::REMOTE_WAIT_LIMIT, 3600).' hours — check its heavy queue worker.');
        }
    }

    private function outOfTime(): bool
    {
        return time() - $this->startedAt > self::RUN_BUDGET;
    }

    private function later(PanelTransfer $transfer, array $state, int $delay = 0): bool
    {
        $this->save($transfer, $state);
        self::dispatch($transfer->id)->delay(now()->addSeconds($delay));

        return false;
    }

    /** Saves progress, and stops the run when the transfer was cancelled meanwhile. */
    private function save(PanelTransfer $transfer, array $state): void
    {
        if (PanelTransfer::query()->whereKey($transfer->id)->value('status') !== 'running') {
            throw new PanelTransferCancelled;
        }
        $transfer->forceFill(['state' => $state])->save();
    }

    /** A subdomain waits while its main website is still being copied over the same connection. */
    private function waitingForParent(PanelTransfer $transfer): bool
    {
        $parent = (string) ($transfer->options['site']['parent_domain'] ?? '');
        if ($transfer->kind !== 'website' || ($transfer->options['site']['type'] ?? '') !== 'sub' || $parent === '' || ! empty($transfer->state)) {
            return false;
        }

        return PanelTransfer::query()->where('connection_id', $transfer->connection_id)->where('domain', strtolower($parent))
            ->whereIn('status', PanelTransfer::ACTIVE)->whereKeyNot($transfer->id)->exists();
    }

    private function progress(PanelTransfer $transfer, int $percent, string $message): void
    {
        $transfer->forceFill(['percent' => max(0, min(99, $percent)), 'message' => $message])->save();
    }

    private function complete(PanelTransfer $transfer, string $message): bool
    {
        $transfer->forceFill(['status' => 'completed', 'percent' => 100, 'message' => $message, 'finished_at' => now()])->save();

        return true;
    }

    private function fail(PanelTransfer $transfer, string $message): void
    {
        $state = (array) $transfer->state;
        File::deleteDirectory(app(PanelPackageStore::class)->directory($transfer->id));
        if (! empty($state['export_id']) && $transfer->connection) {
            PanelLinkClient::for($transfer->connection)->deleteExport((string) $state['export_id']);
        }
        $transfer->forceFill(['status' => 'failed', 'message' => mb_substr($message, 0, 2000), 'finished_at' => now()])->save();
    }

    private function label(PanelTransfer $transfer): string
    {
        $what = $transfer->kind === 'panel' ? 'full panel backup' : (string) $transfer->domain;

        return $transfer->direction === 'pull' ? "{$what} from {$transfer->connection?->name}" : "{$what} to {$transfer->connection?->name}";
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 2).' GB' : round($bytes / 1024 ** 2, 1).' MB';
    }
}
