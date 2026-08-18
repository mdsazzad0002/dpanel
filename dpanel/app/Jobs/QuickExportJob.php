<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\QuickExportJobStatus;
use App\Services\Backup\QuickExportLinkFactory;
use App\Services\Backup\WebsiteArchiver;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class QuickExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $exportId,
        public string $websiteId,
        public int $userId,
        public string $content,
        public ?string $databaseId = null,
    ) {
        // Full backups run here too and already need the 'heavy' queue's 3600s
        // worker timeout (see /etc config for queue:work --queue=heavy) — a
        // large site's mysqldump/zip genuinely takes that long.
        $this->onQueue('heavy');
    }

    public function handle(WebsiteArchiver $archiver, QuickExportLinkFactory $links, NotificationService $notifications): void
    {
        QuickExportJobStatus::set($this->exportId, ['stage' => 'zipping']);

        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;
        if (! $user || ! $website) {
            QuickExportJobStatus::set($this->exportId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            $this->fail($notifications, $user, $website, 'dRust backup API is not configured.');

            return;
        }

        $database = null;
        if ($this->content === 'database') {
            $database = DatabaseRequest::query()
                ->visibleTo($user)
                ->where('domain', $website->domain)
                ->where('status', 'active')
                ->where('id', (string) $this->databaseId)
                ->first();
            if (! $database instanceof DatabaseRequest) {
                $this->fail($notifications, $user, $website, 'Selected database was not found.');

                return;
            }
        }

        $safeDomain = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $website->domain) ?: 'website';
        $itemLabel = $this->content === 'database' ? 'SQL — '.$database->database_name : 'Website files';
        $targetPath = storage_path('app/backups/quick-exports/'.Str::uuid().'.zip');
        // "quick_files" produces a flat zip of the project root (no homedir/public_html
        // wrapper or restore manifest) — full backups still use "files" for that layout.
        $archiveContent = $this->content === 'files' ? 'quick_files' : $this->content;

        try {
            $result = $archiver->archive($user, $website, $archiveContent, $baseUrl, $apiToken, $targetPath, $database?->id);
        } catch (Throwable $e) {
            File::delete($targetPath);
            $this->fail($notifications, $user, $website, 'Quick export failed: '.$e->getMessage(), $itemLabel);

            return;
        }

        if (! $result['ok'] || ! is_file($targetPath)) {
            File::delete($targetPath);
            $this->fail($notifications, $user, $website, $result['message'] ?: 'The export archive was not created.', $itemLabel);

            return;
        }

        if ($this->content === 'database') {
            $archive = new ZipArchive;
            if ($archive->open($targetPath) !== true) {
                File::delete($targetPath);
                $this->fail($notifications, $user, $website, 'The SQL export could not be opened.', $itemLabel);

                return;
            }

            // The zip holds one dump per selected database (mysql/<name>.sql) — match
            // the exact entry for the database the user picked, not just "the first .sql".
            $expectedEntry = 'mysql/'.$database->database_name.'.sql';
            $sql = null;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = (string) $archive->getNameIndex($index);
                if (strtolower($entry) === strtolower($expectedEntry) || str_ends_with(strtolower($entry), '.sql')) {
                    $sql = $archive->getFromIndex($index);
                    if (strtolower($entry) === strtolower($expectedEntry)) {
                        break;
                    }
                }
            }
            $archive->close();
            File::delete($targetPath);

            if (! is_string($sql)) {
                $this->fail($notifications, $user, $website, 'No SQL file was produced for this website.', $itemLabel);

                return;
            }

            $safeDatabase = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $database->database_name) ?: $safeDomain.'-database';
            $sqlPath = storage_path('app/backups/quick-exports/'.Str::uuid().'.sql');
            File::put($sqlPath, $sql);

            $downloadUrl = $links->make($this->websiteId, $sqlPath, $safeDatabase.'-'.now()->format('Y-m-d_H-i-s').'.sql');
            $this->succeed($notifications, $user, $website, $itemLabel, $downloadUrl);

            return;
        }

        $fileName = $safeDomain.'-files-'.now()->format('Y-m-d_H-i-s').'.zip';
        $downloadUrl = $links->make($this->websiteId, $targetPath, $fileName);
        $this->succeed($notifications, $user, $website, $itemLabel, $downloadUrl);
    }

    private function succeed(NotificationService $notifications, User $user, Website $website, string $itemLabel, string $downloadUrl): void
    {
        QuickExportJobStatus::set($this->exportId, ['stage' => 'ready', 'download_url' => $downloadUrl]);
        $notifications->notifyUser(
            actor: $user,
            type: 'quick_export',
            status: 'completed',
            title: "Quick export ready: {$website->domain} ({$itemLabel})",
            subject: $website,
            data: ['download_url' => $downloadUrl],
        );
    }

    private function fail(NotificationService $notifications, User $user, Website $website, string $message, string $itemLabel = ''): void
    {
        QuickExportJobStatus::set($this->exportId, ['stage' => 'failed', 'message' => $message]);
        $notifications->notifyUser(
            actor: $user,
            type: 'quick_export',
            status: 'blocked',
            title: $itemLabel !== '' ? "Quick export failed: {$website->domain} ({$itemLabel})" : "Quick export failed: {$website->domain}",
            message: $message,
            subject: $website,
        );
    }
}
