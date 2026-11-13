<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\MigrationImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** storage:prune removes what a lost delayed queue job left behind, and only that. */
class PruneStorageTest extends TestCase
{
    use RefreshDatabase;

    private function aged(string $path, int $hours): void
    {
        touch($path, now()->subHours($hours)->getTimestamp());
    }

    public function test_old_imports_restore_folders_and_exports_go_and_recent_ones_stay(): void
    {
        // Creating an import queues its own delayed clean-up; the sync test queue would run it at once.
        Queue::fake();
        $root = storage_path('app/migrations');
        $oldId = (string) Str::uuid();
        File::ensureDirectoryExists("{$root}/{$oldId}");
        File::put("{$root}/{$oldId}/site.zip", 'old');
        $old = MigrationImport::query()->create(['id' => $oldId, 'provider' => 'generic', 'original_name' => 'site.zip', 'archive_path' => "{$root}/{$oldId}/site.zip", 'archive_size' => 3, 'status' => 'completed', 'inventory' => ['upload_dir' => "{$root}/{$oldId}"]]);
        $old->forceFill(['created_at' => now()->subHours(9)])->save();

        $newId = (string) Str::uuid();
        File::ensureDirectoryExists("{$root}/{$newId}");
        MigrationImport::query()->create(['id' => $newId, 'provider' => 'generic', 'original_name' => 'new.zip', 'archive_path' => "{$root}/{$newId}/new.zip", 'archive_size' => 3, 'status' => 'ready', 'inventory' => ['upload_dir' => "{$root}/{$newId}"]]);

        File::ensureDirectoryExists("{$root}/.generic-1-old/app");
        $this->aged("{$root}/.generic-1-old", 30);
        File::ensureDirectoryExists("{$root}/.generic-2-running");

        $exports = storage_path('app/backups/quick-exports');
        File::ensureDirectoryExists($exports);
        File::put("{$exports}/old.zip", 'x');
        $this->aged("{$exports}/old.zip", 10);
        File::put("{$exports}/fresh.zip", 'x');

        $this->artisan('storage:prune --dry-run')->assertSuccessful();
        $this->assertDirectoryExists("{$root}/.generic-1-old");

        $this->artisan('storage:prune')->assertSuccessful();

        $this->assertDirectoryDoesNotExist("{$root}/{$oldId}");
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertDirectoryExists("{$root}/{$newId}");
        $this->assertDirectoryDoesNotExist("{$root}/.generic-1-old");
        $this->assertDirectoryExists("{$root}/.generic-2-running");
        $this->assertFileDoesNotExist("{$exports}/old.zip");
        $this->assertFileExists("{$exports}/fresh.zip");
    }

    public function test_a_cron_line_that_runs_php_fpm_is_rewritten_to_the_cli(): void
    {
        $job = CronJob::query()->create([
            'id' => 'trash-backups-prune', 'website_id' => 'none', 'domain' => 'panel.test', 'name' => 'Trash Backup Cleanup',
            'expression' => '30 3 * * *', 'status' => 'active',
            'command' => 'cd /var/www/dpanel && /usr/sbin/php-fpm8.3 artisan dpanel:trash-backups-prune --days=2 >> /dev/null 2>&1',
        ]);

        $this->artisan('storage:prune')->assertSuccessful();

        $this->assertStringNotContainsString('php-fpm', $job->fresh()->command);
        $this->assertStringContainsString(' artisan dpanel:trash-backups-prune --days=2', $job->fresh()->command);
    }
}
