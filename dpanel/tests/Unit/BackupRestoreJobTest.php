<?php

namespace Tests\Unit;

use App\Http\Controllers\BackupController;
use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupRestoreStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class BackupRestoreJobTest extends TestCase
{
    private string $storageRoot;

    // Keep these tests' files out of the real storage/: run as a login user,
    // they left root dirs (storage/app/migrations/site-push, restore-progress)
    // the www-data worker then couldn't create subdirectories in.
    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/dpanel-test-storage-'.bin2hex(random_bytes(6));
        foreach (['app', 'framework/views', 'framework/cache/data', 'logs'] as $directory) {
            File::ensureDirectoryExists($this->storageRoot.'/'.$directory);
        }
        $this->app->useStoragePath($this->storageRoot);
        config([
            'serverpanel.execution_api_token' => 'test-token',
            'serverpanel.execution_api_base_url' => 'http://drust.test',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_restore_is_queued_and_reports_its_status(): void
    {
        Queue::fake();
        $runName = sprintf('%08d_%06d', random_int(10_000_000, 99_999_999), random_int(0, 999_999));
        $runPath = storage_path('app/backups/'.$runName);
        File::ensureDirectoryExists($runPath);
        File::put($runPath.'/site.tar.gz', 'archive');
        $encoded = rtrim(strtr(base64_encode('site.tar.gz'), '+/', '-_'), '=');

        try {
            $controller = (new ReflectionClass(BackupController::class))->newInstanceWithoutConstructor();
            $response = $controller->restore(Request::create('/'), 'token', $runName, $encoded);

            $this->assertSame(200, $response->getStatusCode());
            $restoreId = $response->getData(true)['restore_id'];
            Queue::assertPushed(RestoreBackupJob::class, fn (RestoreBackupJob $job) => $job->restoreId === $restoreId);
            $this->assertSame('queued', $controller->restoreStatus('token', $restoreId)->getData(true)['stage']);
        } finally {
            File::deleteDirectory($runPath);
        }
    }

    public function test_failed_drust_restore_records_the_step_it_failed_in(): void
    {
        $restoreId = (string) Str::uuid();
        BackupRestoreStatus::set($restoreId, ['stage' => 'queued']);
        Http::fake(function () use ($restoreId) {
            // dRust writes the step it died in before answering.
            File::put(BackupRestoreStatus::progressPath($restoreId), json_encode([
                'stage' => 'failed', 'failed_stage' => 'disk_check', 'message' => 'not enough disk space',
            ]));

            return Http::response(['success' => false, 'message' => 'Restore failed: not enough disk space'], 200);
        });

        (new RestoreBackupJob($restoreId, '/tmp/site.tar.gz'))->handle();

        $status = BackupRestoreStatus::get($restoreId);
        $this->assertSame('failed', $status['stage']);
        $this->assertSame('disk_check', $status['failed_step']);
        $this->assertSame('Restore failed: not enough disk space', $status['message']);
        $this->assertFileDoesNotExist(BackupRestoreStatus::progressPath($restoreId));
    }
}
