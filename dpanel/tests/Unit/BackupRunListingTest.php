<?php

namespace Tests\Unit;

use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class BackupRunListingTest extends TestCase
{
    public function test_internal_backup_directories_are_not_listed_as_downloadable_runs(): void
    {
        $root = sys_get_temp_dir().'/dpanel-backup-list-'.bin2hex(random_bytes(8));
        File::makeDirectory($root.'/clone-share', 0755, true);
        File::makeDirectory($root.'/quick-export', 0755, true);
        File::makeDirectory($root.'/20260827_123456', 0755, true);

        try {
            $reflection = new ReflectionClass(BackupController::class);
            $controller = $reflection->newInstanceWithoutConstructor();
            $method = $reflection->getMethod('listRuns');
            $runs = $method->invoke($controller, $root);

            $this->assertSame(['20260827_123456'], array_column($runs, 'name'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_delete_does_not_report_success_while_the_run_still_exists_on_disk(): void
    {
        $runName = sprintf('%08d_%06d', random_int(10_000_000, 99_999_999), random_int(0, 999_999));
        $runPath = storage_path('app/backups/'.$runName);
        File::ensureDirectoryExists($runPath);
        File::put($runPath.'/backup.zip', 'backup');
        config([
            'serverpanel.execution_api_token' => 'test-token',
            'serverpanel.execution_api_base_url' => 'http://drust.test',
        ]);
        Http::fake(['http://drust.test/api/v1/backup/delete' => Http::response(['success' => true], 200)]);

        try {
            $controller = (new ReflectionClass(BackupController::class))->newInstanceWithoutConstructor();
            $response = $controller->destroyRun('token', $runName);

            $this->assertSame(500, $response->getStatusCode());
            $this->assertTrue(File::isDirectory($runPath));
            $this->assertFalse($response->getData(true)['ok']);
        } finally {
            File::deleteDirectory($runPath);
        }
    }
}
