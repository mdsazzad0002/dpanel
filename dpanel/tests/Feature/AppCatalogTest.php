<?php

namespace Tests\Feature;

use App\Http\Requests\Website\AppInstallRequest;
use App\Models\CronJob;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\Cron\CronSystemService;
use App\Services\EdgeGatewayReloader;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Website\AppInstallService;
use App\Services\Website\Apps\AppInstallerRegistry;
use App\Services\Website\Apps\AppInstallFailed;
use App\Services\Website\Apps\AppInstallSupport;
use App\Services\Website\Apps\MediawikiInstaller;
use App\Services\Website\Apps\NextcloudInstaller;
use App\Services\Website\Apps\OpencartInstaller;
use App\Services\Website\Apps\PrestashopInstaller;
use App\Services\Website\WebsiteDatabaseProvisioner;
use App\Services\Website\WebsiteTemplateCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Backlog U8: Nextcloud, PrestaShop, OpenCart and MediaWiki in the app catalog. */
class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_APPS = ['nextcloud', 'prestashop', 'opencart', 'mediawiki'];

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_new_apps_are_in_the_catalog_and_routed(): void
    {
        $registry = app(AppInstallerRegistry::class);
        foreach ([...self::NEW_APPS, 'joomla', 'drupal', 'codeigniter', 'whmcs'] as $app) {
            $this->assertTrue($registry->has($app), $app);
            $this->assertSame($app, $registry->get($app)->key());
            $definition = $registry->definition($app);
            $this->assertNotEmpty($definition['label']);
            $this->assertContains($definition['database'], ['required', 'optional', 'new']);
        }
        foreach (['websites.apps.installer', 'websites.apps.install', 'websites.apps.install.status'] as $name) {
            $pattern = RouteFacade::getRoutes()->getByName($name)->wheres['app'];
            foreach (self::NEW_APPS as $app) {
                $this->assertMatchesRegularExpression('/^'.$pattern.'$/', $app, $name);
            }
        }
        $this->assertSame(['mariadb', 'postgresql'], $registry->engines('nextcloud'));
        $this->assertSame(['mariadb'], $registry->engines('prestashop'));
        $this->assertSame(['mariadb'], $registry->engines('opencart'));
        $this->assertSame(['mariadb', 'postgresql'], $registry->engines('mediawiki'));
    }

    public function test_request_rules_for_the_new_apps(): void
    {
        $base = ['database_id' => 'new', 'admin_email' => 'a@example.com', 'admin_password' => 'long-enough-pass'];

        $nextcloud = $this->rulesFor('nextcloud');
        $this->assertTrue(Validator::make($base + ['version' => '31.0.9', 'admin_username' => 'admin'], $nextcloud)->passes());
        $this->assertTrue(Validator::make($base + ['version' => '31.0.9', 'admin_username' => 'bad name'], $nextcloud)->fails());

        $presta = $this->rulesFor('prestashop');
        $valid = $base + ['version' => '8.2.1', 'site_name' => 'My Shop', 'admin_name' => 'Jane Doe'];
        $this->assertTrue(Validator::make($valid, $presta)->passes());
        $this->assertTrue(Validator::make(['admin_name' => 'Jane2'] + $valid, $presta)->fails());

        $opencart = $this->rulesFor('opencart');
        $valid = $base + ['version' => '4.1.0.3', 'admin_username' => 'admin'];
        $this->assertTrue(Validator::make($valid, $opencart)->passes());
        $this->assertTrue(Validator::make(['admin_password' => '-starts-with-dash'] + $valid, $opencart)->fails());
        $this->assertTrue(Validator::make(['admin_username' => 'ab'] + $valid, $opencart)->fails());
        $this->assertTrue(Validator::make(['version' => '4.1.0.3.1'] + $valid, $opencart)->fails());

        $wiki = $this->rulesFor('mediawiki');
        $valid = ['version' => '1.43.5', 'database_id' => 'new', 'site_name' => 'Team Wiki', 'admin_username' => 'Wiki Admin', 'admin_password' => 'long-enough-pass'];
        $this->assertTrue(Validator::make($valid, $wiki)->passes());
        $this->assertTrue(Validator::make(['site_name' => 'a[b]'] + $valid, $wiki)->fails());
        $this->assertTrue(Validator::make(['admin_username' => '1admin'] + $valid, $wiki)->fails());
    }

    public function test_release_pickers_choose_newest_stable_per_series(): void
    {
        $index = '<a href="nextcloud-31.0.8.zip">x</a> nextcloud-31.0.10.zip nextcloud-31.0.10.zip.sha256 nextcloud-32.0.1.zip '
            .'nextcloud-29.0.16.zip nextcloud-33.0.0rc1.zip nextcloud-32.0.2.tar.bz2';
        $this->assertSame(['32.0.1', '31.0.10'], app(NextcloudInstaller::class)->pickReleases($index));

        $digest = 'sha256:'.str_repeat('ab', 32);
        $asset = fn (string $repo, string $name, ?string $sha = null) => ['name' => $name, 'digest' => $sha,
            'browser_download_url' => "https://github.com/{$repo}/releases/download/x/{$name}"];
        $presta = app(PrestashopInstaller::class)->pickReleases([
            ['assets' => [$asset('PrestaShop/PrestaShop', 'prestashop_9.0.1.zip', $digest)]],
            ['prerelease' => true, 'assets' => [$asset('PrestaShop/PrestaShop', 'prestashop_9.1.0.zip')]],
            ['assets' => [$asset('PrestaShop/PrestaShop', 'prestashop_8.2.3.zip'), $asset('PrestaShop/PrestaShop', 'prestashop_8.2.10.zip')]],
            ['assets' => [['name' => 'prestashop_8.9.9.zip', 'browser_download_url' => 'https://evil.example/x.zip']]],
            ['assets' => [$asset('PrestaShop/PrestaShop', 'prestashop_1.7.8.11.zip')]],
        ]);
        $this->assertSame(['9.0.1', '8.2.10'], array_column($presta, 'version'));
        $this->assertSame(str_repeat('ab', 32), $presta[0]['sha256']);
        $this->assertNull($presta[1]['sha256']);

        $opencart = app(OpencartInstaller::class)->pickReleases([
            ['assets' => [$asset('opencart/opencart', 'opencart-4.1.0.3.zip')]],
            ['assets' => [$asset('opencart/opencart', 'opencart-4.0.2.3.zip'), $asset('opencart/opencart', 'opencart-4.0.2.10.zip')]],
            ['assets' => [$asset('opencart/opencart', 'opencart-3.0.4.0.zip')]],
        ]);
        $this->assertSame(['4.1.0.3', '4.0.2.10'], array_column($opencart, 'version'));

        $wiki = app(MediawikiInstaller::class);
        $this->assertSame('1.43.10', $wiki->pickRelease('1.43', 'mediawiki-1.43.9.tar.gz mediawiki-1.43.10.tar.gz.sig mediawiki-1.43.10.tar.gz mediawiki-core-1.43.11.tar.gz'));
        $this->assertNull($wiki->pickRelease('1.44', 'mediawiki-1.43.1.tar.gz'));
    }

    public function test_download_with_a_wrong_checksum_is_rejected(): void
    {
        Http::fake(['https://example.test/pkg.zip' => fn () => Http::response(str_repeat('x', 2048))]);
        $support = app(AppInstallSupport::class);

        $file = $support->download('https://example.test/pkg.zip', hash('sha256', str_repeat('x', 2048)), 1024);
        $this->tempFiles[] = $file;
        $this->assertFileExists($file);

        $this->expectException(AppInstallFailed::class);
        $this->expectExceptionMessage('checksum');
        $support->download('https://example.test/pkg.zip', str_repeat('0', 64), 1024);
    }

    public function test_opencart_storage_directory_is_rewritten(): void
    {
        $config = "<?php\ndefine('DIR_SYSTEM', '/x/system/');\ndefine('DIR_STORAGE', DIR_SYSTEM . 'storage/');\ndefine('DB_PREFIX', 'oc_');\n";
        $out = app(OpencartInstaller::class)->withStorageDirectory($config, "/home/a/site-opencart-storage/");

        $this->assertStringContainsString("define('DIR_STORAGE', '/home/a/site-opencart-storage/');", $out);
        $this->assertSame(1, substr_count($out, 'DIR_STORAGE'));
        $this->assertStringContainsString("define('DB_PREFIX', 'oc_');", $out);
    }

    /**
     * The whole install flow for each new app, with drust, the database and
     * the gateway mocked: verified download, unpack into the site root,
     * database, the app's CLI installer, finalize, document root.
     */
    #[DataProvider('newApps')]
    public function test_new_app_installs_through_the_catalog(string $app, array $input, string $installAction, array $expect): void
    {
        $website = $this->website();
        [$packageUrl, $packageBody] = $this->fakeUpstream($app);

        $steps = [];
        $uploaded = [];
        $filemanager = Mockery::mock(FilemanagerService::class);
        $filemanager->shouldReceive('runLaravelInstallerStep')->andReturnUsing(function ($owner, $root, $php, $action, $extra) use (&$steps) {
            $steps[] = ['action' => $action] + $extra;

            return ['success' => true, 'output' => $action === 'php_modules'
                ? implode("\n", ['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'iconv', 'intl', 'json', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'posix', 'SimpleXML', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'])
                : 'ok'];
        });
        $filemanager->shouldReceive('directoryExists')->andReturnUsing(fn (string $path) => str_ends_with($path, '/admin') || str_ends_with($path, '/system/storage'));
        $filemanager->shouldReceive('uploadFile')->andReturnUsing(function ($owner, $remote, $local) use (&$uploaded) {
            $zip = new \ZipArchive();
            $zip->open($local);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $uploaded[] = $zip->getNameIndex($i);
            }
            $zip->close();
        });
        $filemanager->shouldReceive('unzipFile', 'deletePath', 'copyPath', 'movePath', 'writeTextFile');
        $filemanager->shouldReceive('fileExists')->andReturn(true);
        $filemanager->shouldReceive('readTextFile')->andReturn(['content' => "<?php\ndefine('DIR_STORAGE', DIR_SYSTEM . 'storage/');\n"]);
        $this->app->instance(FilemanagerService::class, $filemanager);

        $databases = Mockery::mock(WebsiteDatabaseProvisioner::class);
        $databases->shouldReceive('resolveConfig')->once()->andReturn([
            'engine' => 'mariadb', 'database_name' => 'site_ab12_db', 'database_user' => 'site_ab12_user',
            'database_password' => 'secretA1', 'database_host' => '127.0.0.1', 'database_port' => '3306',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $databases->shouldReceive('provision')->once()->andReturn(['success' => true, 'output' => '']);
        $databases->shouldReceive('record')->once();
        $this->app->instance(WebsiteDatabaseProvisioner::class, $databases);

        $catalog = Mockery::mock(WebsiteTemplateCatalogService::class);
        $catalog->shouldReceive('availablePhpVersions')->andReturn(['8.2', '8.3']);
        $this->app->instance(WebsiteTemplateCatalogService::class, $catalog);
        $this->app->instance(PreOverwriteBackupService::class, Mockery::mock(PreOverwriteBackupService::class));
        $gateway = Mockery::mock(EdgeGatewayReloader::class);
        $gateway->shouldReceive('reloadDomains')->once()->with(['wiki.test']);
        $this->app->instance(EdgeGatewayReloader::class, $gateway);
        $cron = Mockery::mock(CronSystemService::class);
        $cron->shouldReceive('sync')->andReturn('');
        $this->app->instance(CronSystemService::class, $cron);
        if ($app === 'mediawiki') {
            // gpg itself is exercised on a real install; here only that it is asked.
            $support = Mockery::mock(AppInstallSupport::class, [app(FilemanagerService::class), $cron, app(\App\Support\Platform\LocalCommand::class)])->makePartial();
            $support->shouldReceive('verifySignature')->once()->with(Mockery::type('string'), $packageUrl.'.sig', MediawikiInstaller::KEYS_URL);
            $this->app->instance(AppInstallSupport::class, $support);
        }

        $stages = [];
        $result = app(AppInstallService::class)->install($app, $website, $input + ['database_id' => 'new'], null,
            function (string $stage) use (&$stages) {
                $stages[] = $stage;
            });

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(['backing_up', 'downloading', 'creating_database', 'configuring', 'finalizing'], $stages);
        $this->assertStringContainsString('installed on PHP 8.3 with a new database site_ab12_db', $result['message']);
        Http::assertSent(fn ($request) => $request->url() === $packageUrl);

        // The package was unpacked flat into the site root.
        $this->assertContains($expect['file'], $uploaded);
        $this->assertContains('big.bin', $uploaded);

        $actions = array_column($steps, 'action');
        $this->assertContains($installAction, $actions);
        $this->assertSame('finalize', end($actions));
        $install = collect($steps)->firstWhere('action', $installAction)[$app];
        foreach ($expect['payload'] as $key => $value) {
            $this->assertSame($value, $install[$key], $key);
        }
        $this->assertSame(['php_version' => '8.3', 'start_directory' => ''], Website::query()->find($website->id)->only(['php_version', 'start_directory']));
        if ($app === 'nextcloud') {
            $this->assertSame('*/5 * * * *', CronJob::query()->where('website_id', $website->id)->value('expression'));
        }
    }

    /** @return array<string, array<int, mixed>> */
    public static function newApps(): array
    {
        $admin = ['admin_email' => 'admin@wiki.test', 'admin_password' => 'long-enough-pass'];

        return [
            'nextcloud' => ['nextcloud', $admin + ['version' => '32.0.1', 'admin_username' => 'admin'], 'nextcloud_install', [
                'file' => 'occ',
                'payload' => ['admin_user' => 'admin', 'db_type' => 'mysql', 'db_host' => '127.0.0.1:3306', 'data_dir' => '/home/wiki/public_html-nextcloud-data', 'domain' => 'wiki.test', 'url' => 'http://wiki.test'],
            ]],
            'prestashop' => ['prestashop', $admin + ['version' => '9.0.1', 'site_name' => 'Shop', 'admin_name' => 'Jane Doe'], 'prestashop_install', [
                'file' => 'install/index_cli.php',
                'payload' => ['domain' => 'wiki.test', 'firstname' => 'Jane', 'lastname' => 'Doe', 'email' => 'admin@wiki.test', 'db_server' => '127.0.0.1:3306', 'db_name' => 'site_ab12_db'],
            ]],
            'opencart' => ['opencart', $admin + ['version' => '4.1.0.3', 'admin_username' => 'admin'], 'opencart_install', [
                'file' => 'install/cli_install.php',
                'payload' => ['username' => 'admin', 'http_server' => 'http://wiki.test/', 'db_hostname' => '127.0.0.1', 'db_port' => '3306', 'db_prefix' => 'oc_'],
            ]],
            'mediawiki' => ['mediawiki', ['version' => '1.43.5', 'site_name' => 'Team Wiki', 'admin_username' => 'WikiAdmin', 'admin_password' => 'long-enough-pass'], 'mediawiki_install', [
                'file' => 'maintenance/install.php',
                'payload' => ['site_name' => 'Team Wiki', 'admin_user' => 'WikiAdmin', 'server' => 'http://wiki.test', 'db_type' => 'mysql', 'db_server' => '127.0.0.1', 'db_port' => '3306'],
            ]],
        ];
    }

    /** @return array{0: string, 1: string} package URL and body */
    private function fakeUpstream(string $app): array
    {
        $big = random_bytes(10 * 1024 * 1024 + 4096);
        switch ($app) {
            case 'nextcloud':
                $url = NextcloudInstaller::RELEASES_URL.'nextcloud-32.0.1.zip';
                $body = $this->zipBody(['nextcloud/occ' => '<?php', 'nextcloud/index.php' => '<?php', 'nextcloud/big.bin' => $big]);
                Http::fake([
                    NextcloudInstaller::RELEASES_URL => Http::response('nextcloud-32.0.1.zip nextcloud-31.0.9.zip'),
                    $url.'.sha256' => Http::response(hash('sha256', $body).'  nextcloud-32.0.1.zip'),
                    $url => Http::response($body),
                ]);
                break;
            case 'prestashop':
                $url = 'https://github.com/PrestaShop/PrestaShop/releases/download/9.0.1/prestashop_9.0.1.zip';
                $inner = $this->zipBody(['install/index_cli.php' => '<?php', 'index.php' => '<?php', 'admin/index.php' => '<?php', 'big.bin' => $big]);
                $body = $this->zipBody(['prestashop.zip' => $inner, 'index.php' => '<?php // extractor', 'Install_PrestaShop.html' => 'x']);
                Http::fake([
                    'https://api.github.com/repos/PrestaShop/PrestaShop/releases*' => Http::response([['assets' => [
                        ['name' => 'prestashop_9.0.1.zip', 'browser_download_url' => $url, 'digest' => 'sha256:'.hash('sha256', $body)],
                    ]]]),
                    $url => Http::response($body),
                ]);
                break;
            case 'opencart':
                $url = 'https://github.com/opencart/opencart/releases/download/4.1.0.3/opencart-4.1.0.3.zip';
                $body = $this->zipBody(['upload/install/cli_install.php' => '<?php', 'upload/index.php' => '<?php', 'upload/big.bin' => $big, 'license.txt' => 'GPL']);
                Http::fake([
                    'https://api.github.com/repos/opencart/opencart/releases*' => Http::response([['assets' => [
                        ['name' => 'opencart-4.1.0.3.zip', 'browser_download_url' => $url, 'digest' => 'sha256:'.hash('sha256', $body)],
                    ]]]),
                    $url => Http::response($body),
                ]);
                break;
            default:
                $url = MediawikiInstaller::RELEASES_URL.'1.43/mediawiki-1.43.5.tar.gz';
                $body = $this->tarGzBody(['mediawiki-1.43.5/maintenance/install.php' => '<?php', 'mediawiki-1.43.5/index.php' => '<?php', 'mediawiki-1.43.5/big.bin' => $big]);
                Http::fake([
                    MediawikiInstaller::RELEASES_URL.'1.43/' => Http::response('mediawiki-1.43.4.tar.gz mediawiki-1.43.5.tar.gz'),
                    MediawikiInstaller::RELEASES_URL.'*/' => Http::response('', 404),
                    $url => Http::response($body),
                ]);
        }

        return [$url, $body];
    }

    /** @param  array<string, string>  $files */
    private function zipBody(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.zip';
        $this->tempFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    /** @param  array<string, string>  $files */
    private function tarGzBody(array $files): string
    {
        $base = sys_get_temp_dir().'/catalog_'.Str::random(8);
        $tar = new \PharData($base.'.tar');
        foreach ($files as $name => $content) {
            $tar->addFromString($name, $content);
        }
        $tar->compress(\Phar::GZ);
        $this->tempFiles[] = $base.'.tar';
        $this->tempFiles[] = $base.'.tar.gz';

        return (string) file_get_contents($base.'.tar.gz');
    }

    private function website(): Website
    {
        $owner = User::factory()->create();

        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'wiki.test', 'hostname' => 'wiki.test',
            'project_root' => '/home/wiki', 'root_path' => '/home/wiki/public_html', 'site_owner' => 'wiki',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function rulesFor(string $app): array
    {
        $request = AppInstallRequest::create('/x', 'POST');
        $route = new Route('POST', '/websites/{id}/apps/{app}/install', []);
        $route->bind(Request::create("/websites/1/apps/{$app}/install", 'POST'));
        $request->setRouteResolver(fn () => $route);

        return $request->rules();
    }
}
