<?php

namespace Tests\Unit;

use App\Models\Website;
use App\Services\EdgeCacheClient;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Seo\IconSetInstaller;
use Mockery;
use Tests\TestCase;

class IconSetInstallerTest extends TestCase
{
    private string $root;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/icons-test-'.uniqid();
        $this->tmp = sys_get_temp_dir().'/icons-src-'.uniqid();
        mkdir($this->root.'/public', 0777, true);
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->root.'/public/*') ?: [], ...glob($this->tmp.'/*') ?: []] as $file) {
            @unlink($file);
        }
        @rmdir($this->root.'/public');
        @rmdir($this->root);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function website(array $attributes = []): Website
    {
        return (new Website)->forceFill($attributes + [
            'domain' => 'shop.test', 'runtime' => 'php', 'site_owner' => 'shop', 'root_path' => $this->root, 'start_directory' => 'public',
        ]);
    }

    private function file(string $name, string $body): string
    {
        file_put_contents($path = "{$this->tmp}/{$name}", $body);

        return $path;
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function installer(?FilemanagerService $files = null, ?EdgeCacheClient $cache = null): IconSetInstaller
    {
        return new IconSetInstaller($files ?? Mockery::mock(FilemanagerService::class), $cache ?? Mockery::mock(EdgeCacheClient::class));
    }

    public function test_target_lists_files_that_would_be_replaced(): void
    {
        touch($this->root.'/public/favicon.ico');
        $target = $this->installer()->target($this->website(), '', ['favicon.ico', 'apple-touch-icon.png', 'evil.php']);

        $this->assertTrue($target['writable']);
        $this->assertSame(['/favicon.ico'], $target['existing']);
        $this->assertFalse($this->installer()->target($this->website(['runtime' => 'node']), '', ['favicon.ico'])['writable']);
    }

    public function test_install_writes_into_the_folder_with_favicon_ico_at_the_root(): void
    {
        $public = $this->root.'/public';
        $files = Mockery::mock(FilemanagerService::class);
        $files->shouldReceive('ensureDirectoryExists')->once()->with('shop', "{$public}/icons");
        $files->shouldReceive('uploadFile')->once()->with('shop', "{$public}/favicon.ico", Mockery::any());
        $files->shouldReceive('uploadFile')->once()->with('shop', "{$public}/icons/favicon-32x32.png", Mockery::any());
        $files->shouldReceive('uploadFile')->once()->with('shop', "{$public}/icons/site.webmanifest", Mockery::any());
        $files->shouldReceive('uploadFile')->once()->with('shop', "{$public}/icons/browserconfig.xml", Mockery::any());
        $cache = Mockery::mock(EdgeCacheClient::class);
        $cache->shouldReceive('purge')->once()->andReturn(1);

        $written = $this->installer($files, $cache)->install($this->website(), 'icons', [
            'favicon.ico' => $this->file('a.ico', "\0\0\1\0".str_repeat("\0", 20)),
            'favicon-32x32.png' => $this->file('b.png', $this->png(32, 32)),
            'site.webmanifest' => $this->file('c.json', '{"name":"Shop","icons":[]}'),
            'browserconfig.xml' => $this->file('d.xml', '<?xml version="1.0"?><browserconfig><msapplication/></browserconfig>'),
        ]);

        $this->assertSame(['/favicon.ico', '/icons/favicon-32x32.png', '/icons/site.webmanifest', '/icons/browserconfig.xml'], $written);
    }

    /** @dataProvider rejected */
    public function test_unexpected_files_are_rejected(string $name, string $body, string $folder = ''): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer()->install($this->website(), $folder, [$name => $this->file('x', $body)]);
    }

    public static function rejected(): array
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($image);
        $png16 = ob_get_clean();

        return [
            'unknown name' => ['index.php', '<?php echo 1;'],
            'wrong size' => ['favicon-32x32.png', $png16],
            'not a png' => ['apple-touch-icon.png', 'GIF89a'],
            'svg with script' => ['favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'svg with handler' => ['favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
            'manifest list' => ['site.webmanifest', '[1,2]'],
            'xml entity' => ['browserconfig.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><browserconfig>&e;</browserconfig>'],
            'folder traversal' => ['favicon.ico', "\0\0\1\0", '../etc'],
            'empty file' => ['favicon.ico', ''],
        ];
    }
}
