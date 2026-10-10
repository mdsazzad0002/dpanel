<?php

namespace Tests\Unit;

use App\Models\Website;
use App\Services\EdgeCacheClient;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Seo\RobotsTxtFile;
use Mockery;
use Tests\TestCase;

class RobotsTxtFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/robots-test-'.uniqid();
        mkdir($this->root.'/public', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/public/robots.txt');
        @rmdir($this->root.'/public');
        @rmdir($this->root);
        parent::tearDown();
    }

    private function website(array $attributes = []): Website
    {
        return (new Website)->forceFill($attributes + [
            'domain' => 'shop.test', 'runtime' => 'php', 'site_owner' => 'shop', 'root_path' => $this->root, 'start_directory' => 'public',
        ]);
    }

    public function test_file_lives_in_the_served_folder(): void
    {
        $file = new RobotsTxtFile(Mockery::mock(FilemanagerService::class), Mockery::mock(EdgeCacheClient::class));

        $this->assertSame(['editable' => true, 'reason' => null, 'path' => $this->root.'/public/robots.txt', 'exists' => false], $file->locate($this->website()));
        // A start directory that does not exist falls back to the root, as the gateway does.
        $this->assertSame($this->root.'/robots.txt', $file->locate($this->website(['start_directory' => 'missing']))['path']);
        $this->assertFalse($file->locate($this->website(['runtime' => 'node']))['editable']);
        $this->assertFalse($file->locate($this->website(['root_path' => '/nonexistent/dir']))['editable']);
    }

    public function test_save_normalizes_line_endings_and_purges(): void
    {
        $files = Mockery::mock(FilemanagerService::class);
        $files->shouldReceive('writeTextFile')->once()->with('shop', $this->root.'/public/robots.txt', "User-agent: *\nAllow: /\n");
        $cache = Mockery::mock(EdgeCacheClient::class);
        $cache->shouldReceive('purge')->once()->with('shop.test', ['/robots.txt'])->andReturn(1);

        (new RobotsTxtFile($files, $cache))->save($this->website(), "User-agent: *\r\nAllow: /\r\n\r\n");
    }

    public function test_save_refuses_a_read_only_site(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RobotsTxtFile(Mockery::mock(FilemanagerService::class), Mockery::mock(EdgeCacheClient::class)))
            ->save($this->website(['runtime' => 'docker']), 'User-agent: *');
    }
}
