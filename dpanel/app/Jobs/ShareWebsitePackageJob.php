<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Backup\CloneShareLinkFactory;
use App\Services\Backup\WebsiteArchiver;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * "Share" half of Clone & Share: bundles the website's files + database (via
 * the same dRust archive endpoint QuickExportJob/CloneWebsiteJob use) into one
 * portable package, then hands back a token + unauthenticated download URL
 * (via CloneShareLinkFactory) that a second server's admin can paste into
 * their own Clone & Share page to pull and restore the site there.
 */
class ShareWebsitePackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $shareId,
        public string $websiteId,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(WebsiteArchiver $archiver, CloneShareLinkFactory $links, NotificationService $notifications): void
    {
        CloneShareJobStatus::set($this->shareId, ['stage' => 'archiving']);

        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;
        if (! $user || ! $website) {
            CloneShareJobStatus::set($this->shareId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            $this->fail($notifications, $user, $website, 'dRust backup API is not configured.');

            return;
        }

        $filesPath = storage_path('app/backups/clone-share/'.Str::uuid().'-files.zip');
        $databasePath = null;
        $packagePath = storage_path('app/backups/clone-share/'.Str::uuid().'-package.zip');

        try {
            $filesResult = $archiver->archive($user, $website, 'quick_files', $baseUrl, $apiToken, $filesPath);
            if (! $filesResult['ok'] || ! is_file($filesPath)) {
                $this->fail($notifications, $user, $website, $filesResult['message'] ?: 'Website files could not be archived.');

                return;
            }

            $hasDatabase = DatabaseRequest::query()->visibleTo($user)->where('domain', $website->domain)->where('status', 'active')->exists();
            if ($hasDatabase) {
                CloneShareJobStatus::set($this->shareId, ['stage' => 'exporting_database']);
                $databasePath = storage_path('app/backups/clone-share/'.Str::uuid().'-db.zip');
                $dbResult = $archiver->archive($user, $website, 'database', $baseUrl, $apiToken, $databasePath);
                if (! $dbResult['ok'] || ! is_file($databasePath)) {
                    $this->fail($notifications, $user, $website, $dbResult['message'] ?: 'Database could not be exported.');

                    return;
                }
            }

            CloneShareJobStatus::set($this->shareId, ['stage' => 'packaging']);

            $package = new ZipArchive;
            if ($package->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $this->fail($notifications, $user, $website, 'The share package could not be created.');

                return;
            }
            $package->addFile($filesPath, 'files.zip');
            if ($databasePath !== null) {
                $sourceDb = new ZipArchive;
                if ($sourceDb->open($databasePath) === true) {
                    for ($index = 0; $index < $sourceDb->numFiles; $index++) {
                        $entry = (string) $sourceDb->getNameIndex($index);
                        if (str_ends_with(strtolower($entry), '.sql')) {
                            $package->addFromString('database.sql', (string) $sourceDb->getFromIndex($index));
                            break;
                        }
                    }
                    $sourceDb->close();
                }
            }
            $package->addFromString('manifest.json', json_encode([
                'domain' => $website->domain,
                'php_version' => $website->php_version,
                'has_database' => $databasePath !== null,
                'created_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
            $package->close();
        } catch (Throwable $e) {
            File::delete($packagePath);
            $this->fail($notifications, $user, $website, 'Share package failed: '.$e->getMessage());

            return;
        } finally {
            File::delete($filesPath);
            if ($databasePath !== null) {
                File::delete($databasePath);
            }
        }

        $safeDomain = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $website->domain) ?: 'website';
        $fileName = $safeDomain.'-clone-'.now()->format('Y-m-d_H-i-s').'.zip';
        $link = $links->make($this->websiteId, $packagePath, $fileName);

        CloneShareJobStatus::set($this->shareId, ['stage' => 'ready', 'download_url' => $link['url'], 'token' => $link['token'], 'expires_at' => $link['expires_at']]);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Share link ready: {$website->domain}",
            subject: $website,
            data: ['download_url' => $link['url']],
        );
    }

    private function fail(NotificationService $notifications, User $user, Website $website, string $message): void
    {
        CloneShareJobStatus::set($this->shareId, ['stage' => 'failed', 'message' => $message]);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'blocked',
            title: "Share link failed: {$website->domain}",
            message: $message,
            subject: $website,
        );
    }
}
