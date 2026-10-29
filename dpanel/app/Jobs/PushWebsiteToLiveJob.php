<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ClonePackageBuilder;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\NotificationService;
use App\Services\SitePush\SitePushClient;
use App\Services\SitePush\SitePushException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Local side of Push to Live: packages this website (ClonePackageBuilder),
 * uploads it in chunks to a live panel's /api/site-push/v1, asks it to
 * restore, then mirrors the live restore's progress back here.
 *
 * A multi-GB upload over a home connection can outlast the heavy worker's
 * 3600s limit, so each run works for at most RUN_BUDGET seconds, saves where
 * it got to, and queues a fresh copy of itself to carry on from that chunk.
 */
class PushWebsiteToLiveJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    private const RUN_BUDGET = 3000;

    private const STATE_TTL_HOURS = 48;

    /** Last stage reported, so a failure can say which step it died in. */
    private string $stage = 'queued';

    public function __construct(
        public string $pushId,
        public string $websiteId,
        public int $userId,
        public string $liveUrl,
        public string $encryptedToken,
    ) {
        $this->onQueue('heavy');
    }

    public static function stateKey(string $pushId): string
    {
        return 'site-push-local:'.$pushId;
    }

    public function handle(ClonePackageBuilder $builder, NotificationService $notifications): void
    {
        $startedAt = time();
        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;
        if (! $user || ! $website) {
            $this->status('failed', 0, 'Website account not found.');

            return;
        }
        $client = new SitePushClient($this->liveUrl, Crypt::decryptString($this->encryptedToken));
        $state = (array) Cache::get(self::stateKey($this->pushId), []);

        try {
            if (empty($state['upload_id'])) {
                $state = $this->prepare($client, $builder, $user, $website);
            }

            while ($state['next_index'] < $state['total']) {
                if (time() - $startedAt > self::RUN_BUDGET) {
                    $this->continueLater($state);

                    return;
                }
                $index = (int) $state['next_index'];
                $client->uploadChunk($state['upload_id'], $index, $this->readChunk($state, $index));
                $state['next_index'] = $index + 1;
                $this->saveState($state);
                $this->status('uploading', 30 + (int) floor(55 * $state['next_index'] / $state['total']), sprintf(
                    'Uploading to %s: %s / %s (chunk %d of %d)',
                    $state['live_domain'], $this->bytes(min($state['size'], $state['next_index'] * $state['chunk_size'])), $this->bytes($state['size']), $state['next_index'], $state['total'],
                ));
            }

            if (empty($state['completed'])) {
                $this->status('finishing', 86, 'Live panel is verifying the upload');
                $client->complete($state['upload_id']);
                $state['completed'] = true;
                $this->saveState($state);
                File::delete($state['package_path']);
            }

            $remoteStageLabels = [
                'queued' => [87, 'Waiting for the live panel\'s worker'],
                'extracting' => [89, 'Live panel is unpacking the package'],
                'backing_up_target' => [92, 'Live panel is moving the current site to .trash'],
                'restoring' => [95, 'Live panel is restoring files and database'],
            ];
            while (true) {
                $remote = $client->status($state['upload_id']);
                $stage = (string) ($remote['stage'] ?? '');
                if ($stage === 'completed') {
                    break;
                }
                if ($stage === 'failed') {
                    throw new SitePushException('Live panel could not restore the site: '.($remote['message'] ?? 'unknown error'));
                }
                [$percent, $label] = $remoteStageLabels[$stage] ?? [87, 'Live panel is working'];
                $this->status('remote_'.$stage, $percent, $label);
                if (time() - $startedAt > self::RUN_BUDGET) {
                    $this->continueLater($state);

                    return;
                }
                sleep(5);
            }
        } catch (Throwable $e) {
            $this->finish($state);
            $this->status('failed', 0, $e->getMessage(), $this->stage);
            $notifications->notifyUser(actor: $user, type: 'clone_website', status: 'blocked', title: "Push to live failed: {$website->domain}", message: $e->getMessage(), subject: $website);

            return;
        }

        $this->finish($state);
        $this->status('completed', 100, "{$website->domain} is now live on {$state['live_domain']}. The live site's previous files are in its .trash.");
        $notifications->notifyUser(actor: $user, type: 'clone_website', status: 'completed', title: "Pushed to live: {$website->domain} → {$state['live_domain']}", subject: $website);
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish((array) Cache::get(self::stateKey($this->pushId), []));
        $previous = (string) (CloneShareJobStatus::get($this->pushId)['stage'] ?? '');
        $this->status('failed', 0, 'Push stopped: '.($exception?->getMessage() ?: 'worker timeout or crash'), $previous);
    }

    private function prepare(SitePushClient $client, ClonePackageBuilder $builder, User $user, Website $website): array
    {
        // Check the host URL + token against the live panel before spending minutes packaging.
        $this->status('connecting', 2, 'Connecting to '.$this->liveUrl);
        $live = $client->handshake();

        $labels = ['archiving' => [5, 'dRust is packaging the website files and database']];
        $packagePath = storage_path('app/backups/clone-share/push-'.$this->pushId.'.zip');
        $package = $builder->build($user, $website, $packagePath, function (string $stage) use ($labels): void {
            [$percent, $label] = $labels[$stage] ?? [10, $stage];
            $this->status($stage, $percent, $label);
        });

        $size = (int) filesize($packagePath);
        $chunkSize = max(1024 * 1024, (int) $live['chunk_size']);
        $total = max(1, (int) ceil($size / $chunkSize));
        if ($total > (int) $live['max_chunks']) {
            throw new SitePushException('The package ('.$this->bytes($size).') is larger than the live panel accepts.');
        }
        $this->status('uploading', 30, 'Starting upload ('.$this->bytes($size).')');
        $upload = $client->startUpload($size, $total, (string) hash_file('sha256', $packagePath), (string) $website->domain, $package['has_database']);

        $state = [
            'package_path' => $packagePath,
            'upload_id' => (string) $upload['upload_id'],
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total' => $total,
            'next_index' => 0,
            'live_domain' => (string) ($live['domain'] ?: parse_url($this->liveUrl, PHP_URL_HOST)),
            'warning' => $package['warning'],
        ];
        $this->saveState($state);

        return $state;
    }

    private function readChunk(array $state, int $index): string
    {
        $handle = fopen($state['package_path'], 'rb');
        if ($handle === false) {
            throw new SitePushException('The push package is gone — start the push again.');
        }
        fseek($handle, $index * $state['chunk_size']);
        $bytes = (string) fread($handle, $state['chunk_size']);
        fclose($handle);

        return $bytes;
    }

    private function continueLater(array $state): void
    {
        $this->saveState($state);
        self::dispatch($this->pushId, $this->websiteId, $this->userId, $this->liveUrl, $this->encryptedToken);
    }

    private function saveState(array $state): void
    {
        Cache::put(self::stateKey($this->pushId), $state, now()->addHours(self::STATE_TTL_HOURS));
    }

    private function finish(array $state): void
    {
        if (! empty($state['package_path'])) {
            File::delete($state['package_path']);
        }
        Cache::forget(self::stateKey($this->pushId));
    }

    private function status(string $stage, int $percent, string $message, ?string $failedStage = null): void
    {
        if ($stage !== 'failed') {
            $this->stage = $stage;
        }
        $state = (array) Cache::get(self::stateKey($this->pushId), []);
        CloneShareJobStatus::set($this->pushId, array_filter([
            'stage' => $stage,
            'failed_stage' => $failedStage,
            'percent' => $percent,
            'message' => $message,
            'warning' => $state['warning'] ?? null,
            'live_url' => $this->liveUrl,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 2).' GB' : round($bytes / 1024 ** 2, 1).' MB';
    }
}
