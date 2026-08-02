<?php

namespace App\Console\Commands;

use App\Models\WebsiteTrashBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PruneWebsiteTrashBackupsCommand extends Command
{
    protected $signature = 'serverpanel:trash-backups-prune {--days=15}';

    protected $description = 'Remove expired website trash archives through drust';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $failed = false;
        $removed = 0;

        WebsiteTrashBackup::query()
            ->where('created_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(50, function ($backups) use (&$failed, &$removed): void {
                foreach ($backups as $backup) {
                    try {
                        $this->deleteArchive((string) $backup->file_path);
                        $backup->delete();
                        $removed++;
                    } catch (\Throwable $e) {
                        $failed = true;
                        $this->error("Trash backup {$backup->id} failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Removed {$removed} expired website trash backup(s). Retention: {$days} days.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function deleteArchive(string $path): void
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Rust execution API is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/website/archive/delete', [
                'zip_path' => $path,
            ]);
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust archive cleanup failed.'));
        }
    }
}
