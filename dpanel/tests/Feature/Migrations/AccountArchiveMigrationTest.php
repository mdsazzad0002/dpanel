<?php

namespace Tests\Feature\Migrations;

use App\Jobs\InspectMigrationImportJob;
use App\Jobs\RestoreMigrationImportJob;
use App\Models\DatabaseRequest;
use App\Models\MigrationImport;
use App\Models\User;
use App\Models\Website;
use App\Services\Security\UploadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccountArchiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        $this->storage = sys_get_temp_dir().'/dpanel-migration-test-'.Str::random(8);
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
        $this->mock(UploadScanner::class, fn ($mock) => $mock->shouldReceive('assertClean'));
        config(['serverpanel.execution_api_base_url' => 'http://daemon.test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function url(string $path): string
    {
        return "/cpsess{$this->token}{$path}";
    }

    public function test_the_new_provider_pages_render(): void
    {
        $admin = $this->admin();
        foreach (['cpanel-partial', 'directadmin', 'plesk'] as $provider) {
            $this->as($admin)->get($this->url("/migrations/{$provider}"))
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Migrations/Index')->where('provider', $provider));
        }
    }

    public function test_an_upload_must_match_its_provider(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->as($admin)->postJson($this->url('/migrations'), [
            'provider' => 'directadmin', 'archive' => UploadedFile::fake()->create('site.zip', 10),
        ])->assertStatus(422);
        $this->as($admin)->postJson($this->url('/migrations'), [
            'provider' => 'nope', 'archive' => UploadedFile::fake()->create('a.tar.gz', 10),
        ])->assertStatus(422);

        $this->as($admin)->postJson($this->url('/migrations'), [
            'provider' => 'directadmin', 'archive' => UploadedFile::fake()->create('user.admin.bob.tar.zst', 10),
        ])->assertStatus(202);

        $import = MigrationImport::query()->sole();
        $this->assertSame('directadmin', $import->provider);
        $this->assertFileExists($import->archive_path);
        Queue::assertPushed(InspectMigrationImportJob::class);
    }

    public function test_a_partial_backup_keeps_its_files_together_with_the_account(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->as($admin)->postJson($this->url('/migrations'), [
            'provider' => 'cpanel-partial', 'account' => 'Bob Smith',
            'archives' => [UploadedFile::fake()->create('backup-example.com-1-2-2024.tar.gz', 10)],
        ])->assertStatus(422)->assertJsonValidationErrors('account');

        $this->as($admin)->postJson($this->url('/migrations'), [
            'provider' => 'cpanel-partial', 'account' => 'bob', 'domain' => 'Example.com',
            'archives' => [
                UploadedFile::fake()->create('backup-example.com-1-2-2024.tar.gz', 10),
                UploadedFile::fake()->create('bob_wp.sql.gz', 5),
            ],
        ])->assertStatus(202);

        $import = MigrationImport::query()->sole();
        $this->assertDirectoryExists($import->archive_path);
        $this->assertFileExists($import->archive_path.'/bob_wp.sql.gz');
        $this->assertSame(['account' => 'bob', 'domain' => 'example.com'], $import->inventory['options']);

        Http::fake(['daemon.test/*' => Http::response(['success' => true, 'data' => [
            'provider' => 'cpanel-partial', 'account' => 'bob',
            'domains' => [['id' => 'example.com', 'label' => 'example.com', 'document_root' => '/home/bob/public_html']],
            'files' => [['id' => 'public_html', 'label' => 'public_html']],
            'databases' => [['id' => 'bob_wp', 'label' => 'bob_wp']],
        ]])]);
        app()->call([new InspectMigrationImportJob($import->id), 'handle']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://daemon.test/api/v1/migration/cpanel-partial/inspect'
            && $request['archive_path'] === $import->archive_path
            && data_get($request->data(), 'options.account') === 'bob');
        $import->refresh();
        $this->assertSame('ready', $import->status);
        $this->assertSame('bob', $import->inventory['options']['account']);
        $this->assertSame('bob_wp', $import->inventory['databases'][0]['id']);

        $this->as($admin)->deleteJson($this->url("/migrations/{$import->id}"))->assertOk();
        $this->assertDirectoryDoesNotExist($import->archive_path);
    }

    public function test_restoring_creates_websites_for_the_selected_domains(): void
    {
        // Also holds back the 8-hour cleanup job a new import schedules.
        Queue::fake();
        $admin = $this->admin();
        $path = storage_path('app/migrations/backup.tar');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'x');
        $import = MigrationImport::create([
            'id' => (string) Str::uuid(), 'provider' => 'plesk', 'original_name' => 'backup.tar',
            'archive_path' => $path, 'archive_size' => 1, 'status' => 'ready', 'created_by' => $admin->id,
            'inventory' => ['provider' => 'plesk', 'account' => 'jdoe',
                'domains' => [['id' => 'example.com', 'label' => 'example.com', 'document_root' => '/home/jdoe/domains/example.com/httpdocs']],
                'files' => [['id' => 'domains/example.com', 'label' => 'domains/example.com']], 'databases' => []],
        ]);

        $this->as($admin)->postJson($this->url("/migrations/{$import->id}/restore"), ['domains' => ['example.com']])->assertStatus(202);
        Queue::assertPushed(RestoreMigrationImportJob::class, fn ($job) => $job->selection['domains'] === ['example.com']);

        Http::fake(['daemon.test/*' => Http::response(['success' => true, 'data' => ['account' => 'jdoe']])]);
        $selection = ['domains' => ['example.com'], 'files' => [], 'databases' => [], 'full_account' => false];
        app()->call([new RestoreMigrationImportJob($import->id, $selection, null), 'handle']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://daemon.test/api/v1/migration/plesk/restore'
            && $request['selection']['domains'] === ['example.com']);
        $website = Website::query()->where('domain', 'example.com')->sole();
        $this->assertSame('/home/jdoe/domains/example.com/httpdocs', $website->root_path);
        $this->assertSame('jdoe', $website->site_owner);
        $this->assertSame('completed', $import->fresh()->status);
    }

    public function test_restored_databases_get_a_login_and_appear_on_the_databases_page(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $path = storage_path('app/migrations/cpmove-bob.tar.gz');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'x');
        // Already known to the panel: its login is kept.
        DatabaseRequest::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => 'old.test', 'database_name' => 'bob_shop',
            'database_user' => 'shopuser', 'database_password' => 'keep-me', 'database_host' => '127.0.0.1', 'status' => 'active',
        ]);
        $import = MigrationImport::create([
            'id' => (string) Str::uuid(), 'provider' => 'cpanel', 'original_name' => 'cpmove-bob.tar.gz',
            'archive_path' => $path, 'archive_size' => 1, 'status' => 'ready', 'created_by' => $admin->id,
            'inventory' => ['provider' => 'cpanel', 'account' => 'bob',
                'domains' => [['id' => 'example.com', 'label' => 'example.com', 'document_root' => '/home/bob/public_html']],
                'files' => [['id' => 'public_html', 'label' => 'public_html']],
                'databases' => [['id' => 'bob_wp', 'label' => 'bob_wp'], ['id' => 'bob_shop', 'label' => 'bob_shop']]],
        ]);

        Http::fake(['daemon.test/*' => Http::response(['success' => true, 'data' => [
            'account' => 'bob', 'databases' => ['bob_wp', 'bob_shop'],
            'connected' => [['domain' => 'example.com', 'database' => 'bob_wp', 'framework' => 'wordpress', 'config' => '/home/bob/public_html/wp-config.php']],
            'warnings' => [],
        ]])]);
        $selection = ['domains' => [], 'files' => [], 'databases' => [], 'full_account' => true];
        app()->call([new RestoreMigrationImportJob($import->id, $selection, null), 'handle']);

        $sent = null;
        Http::assertSent(function (HttpRequest $request) use (&$sent) {
            if ($request->url() !== 'http://daemon.test/api/v1/migration/cpanel/restore') {
                return false;
            }
            $sent = json_decode($request->body(), true)['credentials'];

            return true;
        });
        $this->assertSame('bob_wp', $sent['bob_wp']['user']);
        $this->assertSame(24, strlen($sent['bob_wp']['password']));
        $this->assertSame(['user' => 'shopuser', 'password' => 'keep-me'], $sent['bob_shop']);

        $wp = DatabaseRequest::query()->where('database_name', 'bob_wp')->sole();
        $this->assertSame('example.com', $wp->domain);
        $this->assertSame($sent['bob_wp']['password'], $wp->database_password);
        $this->assertSame('old.test', DatabaseRequest::query()->where('database_name', 'bob_shop')->sole()->domain);
        $this->assertSame('bob_wp', $import->fresh()->inventory['restore_result']['connected'][0]['database']);
    }

    public function test_wordpress_page_lists_target_websites_and_imports_carry_the_new_address(): void
    {
        $admin = $this->admin();
        $website = Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'blog.test', 'hostname' => 'blog.test', 'scope' => 'local',
            'project_root' => '/home/blog', 'root_path' => '/home/blog/public_html', 'start_directory' => '',
            'site_owner' => 'blog', 'php_version' => '8.3', 'enable_ssl' => true, 'manage_dns' => false,
            'status' => 'live', 'type' => 'primary', 'ssl_mode' => 'none',
        ]);

        $this->as($admin)->get($this->url('/migrations/wordpress'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('provider', 'wordpress')->where('websites.0.domain', 'blog.test'));

        $this->as($admin)->postJson($this->url("/websites/{$website->id}/import"), [
            'archive_name' => 'wordpress.zip', 'archive_size' => 100,
            'database_name_file' => 'wp.sql', 'database_size' => 50,
        ])->assertCreated();
        $this->assertSame('https://blog.test', MigrationImport::query()->sole()->inventory['site_url']);
    }
}
