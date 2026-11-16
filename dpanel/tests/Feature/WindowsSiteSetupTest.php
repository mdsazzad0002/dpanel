<?php

namespace Tests\Feature;

use App\Services\PathService;
use App\Services\Website\WebsiteDatabaseProvisioner;
use App\Support\Platform\LocalCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Backlog F8: what the Windows end-to-end run found. */
class WindowsSiteSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
            'dpanel.database_api_url' => 'http://drust.test/api/v1/database-request',
            'dpanel.database_api_token' => 'secret',
            'app.server_base_dir' => '',
        ]);
        Cache::forget('platform.capabilities');
    }

    public function test_a_pc_names_site_homes_where_drust_keeps_them(): void
    {
        $this->app->instance(LocalCommand::class, new LocalCommand('Windows'));
        Http::fake(['drust.test/capabilities' => Http::response([
            'os' => 'windows', 'profile' => 'local', 'capabilities' => ['websites' => true],
            'sites_root' => 'C:\\Users\\me\\dPanel\\sites',
        ])]);

        $this->assertSame('C:/Users/me/dPanel/sites/shop', PathService::siteHome('shop'));
    }

    public function test_linux_keeps_home_without_asking_drust(): void
    {
        Http::fake();

        $this->assertSame('/home/shop', PathService::siteHome('shop'));
        Http::assertNothingSent();
    }

    public function test_wordpress_reaches_a_mariadb_off_the_default_port(): void
    {
        $host = fn (array $config) => \App\Services\Website\WordpressInstallService::wordpressDbHost($config);

        $this->assertSame('127.0.0.1:3307', $host(['database_host' => '127.0.0.1', 'database_port' => '3307']));
        $this->assertSame('127.0.0.1', $host(['database_host' => '127.0.0.1', 'database_port' => '3306']));
        $this->assertSame('127.0.0.1', $host(['database_host' => '127.0.0.1']));
        $this->assertSame('db.example:3310', $host(['database_host' => 'db.example:3310', 'database_port' => '3307']));
    }

    public function test_a_mariadb_database_is_made_by_drust_not_a_bash_script(): void
    {
        Http::fake(['drust.test/api/v1/database-request' => Http::response(['success' => true, 'message' => 'Database created.'])]);

        $result = app(WebsiteDatabaseProvisioner::class)->provision([
            'engine' => 'mariadb', 'database_name' => 'shop_db', 'database_user' => 'shop_user',
            'database_password' => 'a-long-password', 'database_host' => '127.0.0.1', 'database_port' => '3306',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertSame(['success' => true, 'output' => 'Database created.'], $result);
        Http::assertSent(fn (Request $request) => $request['action'] === 'create'
            && $request['engine'] === 'mariadb'
            && $request['database_name'] === 'shop_db'
            && $request['database_host'] === '127.0.0.1'
            && $request['collation'] === 'utf8mb4_unicode_ci'
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }
}
