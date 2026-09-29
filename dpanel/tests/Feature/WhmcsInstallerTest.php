<?php

namespace Tests\Feature;

use App\Http\Requests\Website\AppInstallRequest;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Website\AppInstallService;
use App\Services\Website\AppPackageUploads;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\TestCase;

class WhmcsInstallerTest extends TestCase
{
    private AppPackageUploads $uploads;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/whmcs-test-'.uniqid();
        $this->uploads = new class($this->root) extends AppPackageUploads {
            public function __construct(private readonly string $base)
            {
            }

            public function root(): string
            {
                return $this->base;
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_chunked_upload_is_assembled_and_bound_to_its_owner(): void
    {
        $zip = $this->makeZip(['whmcs/init.php' => '<?php', 'whmcs/install/bin/installer.php' => '<?php', 'whmcs/admin/index.php' => 'x']);
        $bytes = file_get_contents($zip);
        $half = intdiv(strlen($bytes), 2);

        $id = $this->uploads->init('site-1', 7, strlen($bytes));
        foreach ([substr($bytes, 0, $half), substr($bytes, $half)] as $index => $part) {
            $chunk = tempnam(sys_get_temp_dir(), 'chunk');
            file_put_contents($chunk, $part);
            $this->uploads->storeChunk($id, 'site-1', 7, $index, $chunk);
            unlink($chunk);
        }
        $path = $this->uploads->complete($id, 'site-1', 7, 2);

        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($path, $this->uploads->packagePath($id, 'site-1', 7));
        $this->assertSame('whmcs/', $this->uploads->whmcsBaseDirectory($path));

        // Another user or website cannot use the upload.
        $this->expectException(\RuntimeException::class);
        $this->uploads->packagePath($id, 'site-1', 8);
    }

    public function test_detects_flat_packages_and_rejects_other_zips(): void
    {
        $this->assertSame('', $this->uploads->whmcsBaseDirectory($this->makeZip(['init.php' => '<?php', 'install/bin/installer.php' => '<?php'])));
        $this->assertNull($this->uploads->whmcsBaseDirectory($this->makeZip(['wordpress/index.php' => '<?php'])));
        $this->assertNull($this->uploads->whmcsBaseDirectory($this->makeZip(['a/install/bin/installer.php' => '<?php'])));
    }

    public function test_nested_package_is_repacked_flat_before_upload(): void
    {
        $zip = $this->makeZip(['whmcs/init.php' => 'init', 'whmcs/install/bin/installer.php' => 'inst', 'whmcs/admin/index.php' => 'admin']);
        $entries = null;

        $filemanager = Mockery::mock(FilemanagerService::class);
        $filemanager->shouldReceive('uploadFile')->once()->withArgs(function ($owner, $remote, $local) use (&$entries) {
            $archive = new \ZipArchive();
            $archive->open($local);
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $entries[] = $archive->getNameIndex($i);
            }
            $archive->close();

            return $owner === 'alice' && $remote === '/home/alice/site/.dpanel-app-package.zip';
        });
        $filemanager->shouldReceive('unzipFile')->once()->with('alice', '/home/alice/site/.dpanel-app-package.zip', '/home/alice/site');
        $filemanager->shouldReceive('deletePath')->once();
        $this->app->instance(FilemanagerService::class, $filemanager);

        $service = app(AppInstallService::class);
        $method = new \ReflectionMethod($service, 'deployWhmcsPackage');
        $result = $method->invoke($service, 'alice', '/home/alice/site', $zip, 'whmcs/');

        $this->assertTrue($result['success'], $result['output']);
        sort($entries);
        $this->assertSame(['admin/index.php', 'init.php', 'install/bin/installer.php'], $entries);
    }

    public function test_whmcs_request_requires_upload_license_and_admin(): void
    {
        $rules = $this->rulesFor('whmcs');
        $valid = [
            'version' => 'upload', 'database_id' => 'new', 'upload_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
            'license_key' => 'Owned-abc123', 'admin_username' => 'admin', 'admin_password' => 'long-enough-pass',
        ];

        $this->assertTrue(Validator::make($valid, $rules)->passes());
        $this->assertTrue(Validator::make(['license_key' => 'bad key!'] + $valid, $rules)->fails());
        $this->assertTrue(Validator::make(['upload_id' => 'x'] + $valid, $rules)->fails());
        $this->assertTrue(Validator::make(['admin_password' => 'short'] + $valid, $rules)->fails());
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makeZip(array $files): string
    {
        @mkdir($this->root, 0700, true);
        $path = $this->root.'/'.uniqid('pkg').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content.str_repeat(' ', 2048));
        }
        $zip->close();

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(string $app): array
    {
        $request = AppInstallRequest::create('/x', 'POST');
        $route = new Route('POST', '/websites/{id}/apps/{app}/install', []);
        $route->bind(Request::create("/websites/1/apps/{$app}/install", 'POST'));
        $request->setRouteResolver(fn () => $route);

        return $request->rules();
    }
}
