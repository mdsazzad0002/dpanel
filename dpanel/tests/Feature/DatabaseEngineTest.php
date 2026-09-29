<?php

namespace Tests\Feature;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseEngineTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
        // Normally set by the panel-session middleware, which these tests skip.
        URL::defaults(['token' => $this->token]);
        $this->createSchema();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        config([
            'serverpanel.database_api_url' => 'http://drust.test/api/v1/database-request',
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
            'postgresql.admin_username' => 'postgres',
            'postgresql.admin_password' => 'superpw',
        ]);
        $this->createWebsite();
    }

    public function test_databases_default_to_mariadb(): void
    {
        Http::fake(['drust.test/api/v1/database-request' => Http::response(['success' => true, 'message' => 'ok'])]);

        $this->request()->post("/cpsess{$this->token}/databases", $this->payload())->assertRedirect();

        $database = DatabaseRequest::query()->sole();
        $this->assertSame('mariadb', $database->engine);
        $this->assertSame('utf8mb4', $database->charset);
        Http::assertSent(fn ($request) => $request['engine'] === 'mariadb');
    }

    public function test_postgresql_databases_are_created_through_drust(): void
    {
        Http::fake(['drust.test/api/v1/database-request' => Http::response(['success' => true, 'message' => 'ok'])]);

        $this->request()
            ->post("/cpsess{$this->token}/databases", $this->payload([
                'engine' => 'postgresql',
                'database_host' => '203.0.113.10',
                'charset' => 'latin1',
            ]))
            ->assertRedirect();

        $database = DatabaseRequest::query()->sole();
        $this->assertSame('postgresql', $database->engine);
        $this->assertSame('active', $database->status);
        $this->assertSame('siteowner_shop_db', $database->database_name);
        // Local only, always UTF8, whatever the form sent.
        $this->assertSame('127.0.0.1', $database->database_host);
        $this->assertSame('UTF8', $database->charset);
        Http::assertSent(fn ($request) => $request['engine'] === 'postgresql'
            && $request['database_name'] === 'siteowner_shop_db');
    }

    public function test_unknown_engines_are_rejected(): void
    {
        Http::fake();

        $this->request()
            ->post("/cpsess{$this->token}/databases", $this->payload(['engine' => 'oracle']))
            ->assertSessionHasErrors('engine');

        $this->assertSame(0, DatabaseRequest::query()->count());
        Http::assertNothingSent();
    }

    public function test_editing_keeps_the_original_engine(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'message' => 'ok'])]);
        $database = $this->createDatabase('postgresql');

        $this->request()
            ->patch("/cpsess{$this->token}/databases/{$database->id}", $this->payload([
                'engine' => 'mariadb',
                'database_name' => $database->database_name,
                'database_user' => $database->database_user,
                'database_password' => 'newpw',
            ]))
            ->assertRedirect();

        $this->assertSame('postgresql', $database->fresh()->engine);
        Http::assertSent(fn ($request) => $request['engine'] === 'postgresql' && $request['database_password'] === 'newpw');
    }

    public function test_db_login_opens_pgadmin_for_postgresql_databases(): void
    {
        Http::fake(['drust.test/api/v1/postgresql/pgadmin-login' => Http::response([
            'success' => true,
            'data' => ['url' => '/pgadmin4/dpanel-sso?ticket='.str_repeat('a', 64)],
        ])]);
        $database = $this->createDatabase('postgresql');

        $this->request()
            ->get("/cpsess{$this->token}/databases/{$database->id}/pgadmin/autologin")
            ->assertRedirect(url('/pgadmin4/dpanel-sso?ticket='.str_repeat('a', 64)));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret')
            && $request['target'] === 'database'
            && $request['database_name'] === $database->database_name
            && $request['database_user'] === $database->database_user
            && $request['database_password'] === $database->database_password);
    }

    public function test_db_login_routes_send_each_engine_to_its_own_tool(): void
    {
        $postgres = $this->createDatabase('postgresql');
        $mariadb = $this->createDatabase('mariadb');

        $this->request()
            ->get("/cpsess{$this->token}/databases/{$postgres->id}/phpmyadmin/autologin")
            ->assertRedirectContains("/databases/{$postgres->id}/pgadmin/autologin");
        $this->request()
            ->get("/cpsess{$this->token}/databases/{$mariadb->id}/pgadmin/autologin")
            ->assertRedirectContains("/databases/{$mariadb->id}/phpmyadmin/autologin");
    }

    public function test_pgadmin_failures_return_to_the_list_with_the_reason(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => false, 'message' => 'Failed: pgAdmin is not installed.'], 400)]);
        $database = $this->createDatabase('postgresql');

        $this->request()
            ->get("/cpsess{$this->token}/databases/{$database->id}/pgadmin/autologin")
            ->assertRedirectContains('/databases')
            ->assertSessionHas('error', 'Could not open pgAdmin: Failed: pgAdmin is not installed.');
    }

    public function test_admin_pgadmin_login_uses_the_superuser(): void
    {
        Http::fake(['drust.test/api/v1/postgresql/pgadmin-login' => Http::response([
            'success' => true,
            'data' => ['url' => '/pgadmin4/dpanel-sso?ticket='.str_repeat('b', 64)],
        ])]);

        $this->request()
            ->get("/cpsess{$this->token}/databases/postgresql/pgadmin")
            ->assertRedirect(url('/pgadmin4/dpanel-sso?ticket='.str_repeat('b', 64)));

        Http::assertSent(fn ($request) => $request['target'] === 'admin'
            && $request['database_user'] === 'postgres'
            && $request['database_password'] === 'superpw');
    }

    public function test_list_links_each_database_to_its_website(): void
    {
        $website = Website::query()->where('domain', 'shop.test')->sole();
        $this->createDatabase('mariadb');
        DatabaseRequest::query()->create([
            'id' => (string) Str::uuid(),
            'domain' => 'gone.test',
            'database_name' => 'orphan_db',
            'database_user' => 'orphan_user',
            'database_password' => 'pw',
            'status' => 'active',
        ]);

        $this->request()
            ->get("/cpsess{$this->token}/databases/list")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Databases/List')
                ->where('databaseRequests', function ($rows) use ($website): bool {
                    $ids = collect($rows)->pluck('website_id', 'domain');

                    return $ids['shop.test'] === (string) $website->id && $ids['gone.test'] === null;
                }));
    }

    public function test_app_installers_only_offer_mariadb_databases(): void
    {
        $website = Website::query()->where('domain', 'shop.test')->sole();
        $mariadb = $this->createDatabase('mariadb');
        $postgres = $this->createDatabase('postgresql');
        $provisioner = app(\App\Services\Website\WebsiteDatabaseProvisioner::class);

        $this->assertSame([$mariadb->id], array_column($provisioner->selectable($website, $this->admin), 'id'));
        $this->assertNull($provisioner->find($website, $this->admin, $postgres->id));
    }

    public function test_laravel_installer_offers_postgresql_databases_with_pgsql_settings(): void
    {
        $website = Website::query()->where('domain', 'shop.test')->sole();
        $mariadb = $this->createDatabase('mariadb');
        $postgres = $this->createDatabase('postgresql');
        config(['postgresql.port' => 5432]);

        $offered = app(\App\Services\Website\LaravelInstallService::class)->selectableDatabases($website, $this->admin);
        $this->assertEqualsCanonicalizing([$mariadb->id, $postgres->id], array_column($offered, 'id'));
        $this->assertSame('postgresql', collect($offered)->firstWhere('id', $postgres->id)['engine']);

        $config = app(\App\Services\Website\WebsiteDatabaseProvisioner::class)->resolveConfig('shop.test', $postgres);
        $this->assertSame('postgresql', $config['engine']);
        $this->assertSame('5432', $config['database_port']);
        $this->assertSame('127.0.0.1', $config['database_host']);
    }

    public function test_new_installer_databases_can_be_postgresql(): void
    {
        config(['postgresql.port' => 5432]);
        $provisioner = app(\App\Services\Website\WebsiteDatabaseProvisioner::class);

        $pg = $provisioner->resolveConfig('shop.test', null, 'ab12', 'laravel', 'postgresql');
        $this->assertSame('postgresql', $pg['engine']);
        $this->assertSame('shop_ab12_db', $pg['database_name']);
        $this->assertSame('5432', $pg['database_port']);
        $this->assertSame('UTF8', $pg['charset']);

        $my = $provisioner->resolveConfig('shop.test', null, 'ab12');
        $this->assertSame('mariadb', $my['engine']);
        $this->assertSame('utf8mb4', $my['charset']);

        // PostgreSQL names can't start with a digit.
        $digits = $provisioner->resolveConfig('123shop.test', null, 'ab12', 'laravel', 'postgresql');
        $this->assertSame('db_123shop_ab12_db', $digits['database_name']);
    }

    public function test_laravel_install_stops_early_when_postgresql_is_off(): void
    {
        $website = Website::query()->where('domain', 'shop.test')->sole();
        $website->update(['php_version' => '8.3']);
        $this->mock(\App\Services\PostgresqlServiceManager::class, function ($mock) {
            $mock->shouldReceive('statuses')->andReturn(['postgresql' => ['installed' => true, 'active' => false]]);
        });
        $filemanager = $this->mock(\App\Services\Filemanager\FilemanagerService::class);
        // Nothing may be moved to trash or downloaded before the pre-check.
        $filemanager->shouldNotReceive('directoryExists');
        $filemanager->shouldNotReceive('runLaravelInstallerStep');

        $service = app(\App\Services\Website\LaravelInstallService::class);
        $version = collect(\App\Services\Website\LaravelInstallService::STACKS['blank']['versions'])->first();
        $result = $service->install($website, [
            'stack' => 'blank',
            'laravel_version' => $version,
            'database_id' => 'new',
            'database_engine' => 'postgresql',
        ], $this->admin);

        if (str_contains($result['message'], 'needs PHP')) {
            $this->markTestSkipped('No PHP version for this Laravel release on the test machine.');
        }
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('PostgreSQL is turned off', $result['message']);
    }

    public function test_install_request_rejects_unknown_engines(): void
    {
        $rules = (new \App\Http\Requests\Website\LaravelInstallRequest())->rules();
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['stack' => 'blank', 'laravel_version' => array_key_first(\App\Services\Website\LaravelInstallService::VERSIONS), 'database_id' => 'new', 'database_engine' => 'oracle'],
            $rules,
        );
        $this->assertTrue($validator->errors()->has('database_engine'));
    }

    public function test_postgresql_provisioning_goes_through_drust(): void
    {
        Http::fake(['drust.test/api/v1/database-request' => Http::response(['success' => true, 'message' => 'ok'])]);
        $postgres = $this->createDatabase('postgresql');
        $provisioner = app(\App\Services\Website\WebsiteDatabaseProvisioner::class);

        $result = $provisioner->provision($provisioner->resolveConfig('shop.test', $postgres));

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request['engine'] === 'postgresql'
            && $request['database_name'] === $postgres->database_name
            && $request['database_password'] === $postgres->database_password);
    }

    public function test_drust_urls_outside_pgadmin_are_not_followed(): void
    {
        Http::fake(['drust.test/*' => Http::response([
            'success' => true,
            'data' => ['url' => 'https://evil.example/'],
        ])]);
        $database = $this->createDatabase('postgresql');

        $this->request()
            ->get("/cpsess{$this->token}/databases/{$database->id}/pgadmin/autologin")
            ->assertRedirectContains('/databases')
            ->assertSessionHas('error');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'domain' => 'shop.test',
            'database_name' => 'shop_db',
            'database_user' => 'shop_user',
            'database_password' => 'Secret123!',
            'database_host' => '127.0.0.1',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ], $overrides);
    }

    private function createDatabase(string $engine): DatabaseRequest
    {
        return DatabaseRequest::query()->create([
            'id' => (string) Str::uuid(),
            'engine' => $engine,
            'domain' => 'shop.test',
            'database_name' => 'siteowner_'.$engine.'_db',
            'database_user' => 'siteowner_'.$engine.'_user',
            'database_password' => 'pw-'.$engine,
            'database_host' => '127.0.0.1',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'status' => 'active',
        ]);
    }

    /**
     * Only the tables these requests touch. A full migrate:fresh does not run
     * on SQLite at the moment (the removed Spatie permission migrations).
     */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('domain');
            $table->string('root_path')->nullable();
            $table->string('site_owner')->nullable();
            $table->string('php_version')->nullable();
            $table->string('app_installer')->nullable();
            $table->string('wordpress_version')->nullable();
            $table->boolean('enable_ssl')->default(false);
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('database_requests', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('engine', 20)->default('mariadb');
            $table->string('domain');
            $table->string('database_name', 64);
            $table->string('database_user', 64);
            $table->string('database_password');
            $table->string('database_host')->default('localhost');
            $table->string('charset', 32)->default('utf8mb4');
            $table->string('collation', 64)->default('utf8mb4_unicode_ci');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamps();
        });
    }

    private function createWebsite(): Website
    {
        return Website::query()->create([
            'id' => (string) Str::uuid(),
            'domain' => 'shop.test',
            'root_path' => '/home/siteowner/public_html',
            'site_owner' => 'siteowner',
            'php_version' => '8.3',
            'app_installer' => 'none',
            'wordpress_version' => 'latest',
            'enable_ssl' => false,
            'assigned_user_id' => null,
            'assigned_reseller_id' => null,
            'status' => 'pending',
        ]);
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
