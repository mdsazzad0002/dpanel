<?php

namespace Tests\Unit;

use App\Services\Filemanager\FilemanagerService;
use App\Services\Website\WebsiteService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebsiteDocumentRootTest extends TestCase
{
    public function test_it_falls_back_to_root_when_configured_public_directory_is_missing(): void
    {
        $root = storage_path('framework/testing/document-root-'.uniqid());
        File::ensureDirectoryExists($root);
        File::put($root.'/index.php', '<?php');

        try {
            $service = new WebsiteService($this->mock(FilemanagerService::class));

            $this->assertSame($root, $service->resolveWebsiteDocumentRoot($root, 'public'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_it_prefers_existing_configured_public_directory(): void
    {
        $root = storage_path('framework/testing/document-root-'.uniqid());
        File::ensureDirectoryExists($root.'/public');
        File::put($root.'/public/index.php', '<?php');
        File::put($root.'/index.php', '<?php');

        try {
            $service = new WebsiteService($this->mock(FilemanagerService::class));

            $this->assertSame($root.'/public', $service->resolveWebsiteDocumentRoot($root, 'public'));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
