<?php

namespace Tests\Unit;

use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\File;
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
}
