<?php

namespace Tests\Feature\PanelLink;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Jobs\BuildPanelPackageJob;
use App\Jobs\ImportPanelPackageJob;
use App\Jobs\RunPanelTransferJob;
use App\Models\PanelConnection;
use App\Models\PanelTransfer;
use App\Services\PanelLink\PanelLinkClient;
use App\Services\PanelLink\PanelPackageImporter;
use App\Services\PanelLink\PanelPackageStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\PublicApi\PublicApiTestCase;

/** dPanel ⇄ dPanel live migration: the API one panel answers and the job the other runs. */
class PanelLinkTest extends PublicApiTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep packages out of the real storage/ (see SitePushTest).
        $this->storageRoot = sys_get_temp_dir().'/dpanel-test-storage-'.bin2hex(random_bytes(6));
        foreach (['app', 'framework/views', 'framework/cache/data', 'logs'] as $directory) {
            File::ensureDirectoryExists($this->storageRoot.'/'.$directory);
        }
        $this->app->useStoragePath($this->storageRoot);
        config(['view.compiled' => $this->storageRoot.'/framework/views']);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_only_an_admin_token_with_a_migration_scope_is_answered(): void
    {
        $admin = $this->user('admin');
        $this->api($this->token($admin, ['websites:read']), 'GET', '/migration/handshake')->assertForbidden();
        $this->api($this->token($admin, ['migration:read']), 'GET', '/migration/handshake')
            ->assertOk()
            ->assertJsonPath('data.scopes', ['migration:read'])
            ->assertJsonPath('data.chunk_size', PanelPackageStore::CHUNK_SIZE);

        // A reseller can't hold the scope, even when it is on the token.
        $reseller = $this->user('reseller');
        $this->api($this->token($reseller, ['migration:write']), 'GET', '/migration/handshake')->assertForbidden();
    }

    public function test_read_scope_can_not_send_packages(): void
    {
        $plain = $this->token($this->user('admin'), ['migration:read']);

        $this->api($plain, 'POST', '/migration/uploads', ['kind' => 'panel', 'size' => 10, 'total' => 1, 'sha256' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_inventory_lists_websites_and_marks_what_can_not_be_copied(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('general', ['email' => 'owner@example.com']);
        $this->website($owner, 'shop.example.com');
        $this->website($owner, 'app.example.com', ['runtime' => 'node']);

        $response = $this->api($this->token($admin, ['migration:read']), 'GET', '/migration/inventory')->assertOk();
        $sites = collect($response->json('data.websites'))->keyBy('domain');

        $this->assertTrue($sites['shop.example.com']['supported']);
        $this->assertSame('owner@example.com', $sites['shop.example.com']['owner']['email']);
        $this->assertFalse($sites['app.example.com']['supported']);
    }

    public function test_a_website_export_is_built_in_the_background_and_only_its_maker_sees_it(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $this->website(null, 'shop.example.com');
        $plain = $this->token($admin, ['migration:read']);

        $export = $this->api($plain, 'POST', '/migration/exports', ['kind' => 'website', 'domain' => 'shop.example.com'])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(BuildPanelPackageJob::class, fn ($job) => $job->exportId === $export);

        $this->api($this->token($this->user('admin'), ['migration:read']), 'GET', "/migration/exports/{$export}")->assertNotFound();
        $this->api($plain, 'POST', '/migration/exports', ['kind' => 'website', 'domain' => 'missing.example.com'])->assertNotFound();
    }

    public function test_a_ready_export_is_downloaded_in_checked_chunks(): void
    {
        $admin = $this->user('admin');
        $store = app(PanelPackageStore::class);
        $meta = $store->create('export', ['kind' => 'panel', 'created_by' => $admin->id]);
        $path = $store->packagePath($meta['id'], 'tar.gz');
        File::put($path, 'panel-backup-bytes');
        $store->update($meta['id'], ['status' => 'ready', 'path' => $path, 'size' => 18, 'total' => 1, 'sha256' => hash('sha256', 'panel-backup-bytes')]);

        $response = $this->withToken($this->token($admin, ['migration:read']))->get("/api/v1/migration/exports/{$meta['id']}/chunks/0")->assertOk();

        $this->assertSame('panel-backup-bytes', $response->getContent());
        $this->assertSame(hash('sha256', 'panel-backup-bytes'), $response->headers->get('X-Chunk-Sha256'));
    }

    public function test_an_upload_is_checked_and_then_imported(): void
    {
        Queue::fake();
        $plain = $this->token($this->user('admin'), ['migration:write']);
        $bytes = 'website-package';

        $this->api($plain, 'POST', '/migration/uploads', ['kind' => 'website', 'size' => 15, 'total' => 2, 'sha256' => hash('sha256', $bytes), 'site' => ['domain' => 'shop.example.com']])
            ->assertUnprocessable();
        $upload = $this->api($plain, 'POST', '/migration/uploads', ['kind' => 'website', 'size' => 15, 'total' => 1, 'sha256' => hash('sha256', $bytes), 'site' => ['domain' => 'shop.example.com']])
            ->assertCreated()->json('data.id');

        $this->withToken($plain)->post("/api/v1/migration/uploads/{$upload}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('c.part', $bytes),
            'sha256' => str_repeat('0', 64),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->withToken($plain)->post("/api/v1/migration/uploads/{$upload}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('c.part', $bytes),
            'sha256' => hash('sha256', $bytes),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->api($plain, 'POST', "/migration/uploads/{$upload}/complete")->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(ImportPanelPackageJob::class, fn ($job) => $job->uploadId === $upload);
        $this->assertSame($bytes, File::get(app(PanelPackageStore::class)->packagePath($upload, 'zip')));
    }

    public function test_a_files_upload_needs_the_website_to_exist_here(): void
    {
        $plain = $this->token($this->user('admin'), ['migration:write']);

        $this->api($plain, 'POST', '/migration/uploads', ['kind' => 'files', 'size' => 5, 'total' => 1, 'sha256' => str_repeat('a', 64), 'site' => ['domain' => 'new.example.com']])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'new.example.com does not exist on this panel yet — copy the whole website first.']);
    }

    public function test_connecting_checks_the_other_panel_first(): void
    {
        $admin = $this->user('admin');
        Http::fake([
            'old.example.com/api/v1/migration/handshake' => Http::response(['data' => ['server' => 'old-box', 'version' => '2.1', 'scopes' => ['migration:read']]]),
            'bad.example.com/*' => Http::response(['message' => 'Invalid, expired or revoked API token.'], 401),
        ]);
        $session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $session]);

        $this->actingAs($admin)->postJson(route('migrations.dpanel.link.connections.store', ['token' => $session]), ['base_url' => 'bad.example.com', 'token' => 'dp-api-x'])
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid, expired or revoked API token.');
        $this->actingAs($admin)->postJson(route('migrations.dpanel.link.connections.store', ['token' => $session]), ['base_url' => 'old.example.com/', 'token' => 'dp-api-x'])
            ->assertCreated()
            ->assertJsonPath('connection.name', 'old-box')
            ->assertJsonPath('connection.base_url', 'https://old.example.com')
            ->assertJsonPath('connection.can_pull', true)
            ->assertJsonPath('connection.can_push', false);

        $this->assertSame('dp-api-x', PanelConnection::query()->firstOrFail()->token);
    }

    public function test_a_read_only_connection_can_not_push(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $connection = PanelConnection::query()->create(['user_id' => $admin->id, 'name' => 'old', 'base_url' => 'https://old.example.com', 'token' => 'dp-api-x', 'remote_scopes' => ['migration:read']]);
        $session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $session]);

        $this->actingAs($admin)->postJson(route('migrations.dpanel.link.connections.transfers', ['token' => $session, 'connection' => $connection->id]), [
            'direction' => 'push', 'kind' => 'website', 'sites' => [['domain' => 'shop.example.com']],
        ])->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('migrations.dpanel.link.connections.transfers', ['token' => $session, 'connection' => $connection->id]), [
            'direction' => 'pull', 'kind' => 'panel',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        $this->actingAs($admin)->postJson(route('migrations.dpanel.link.connections.transfers', ['token' => $session, 'connection' => $connection->id]), [
            'direction' => 'pull', 'kind' => 'website', 'sites' => [['domain' => 'blog.example.com', 'type' => 'sub', 'parent_domain' => 'example.com'], ['domain' => 'example.com', 'type' => 'main']],
        ])->assertStatus(202);
        // The main website is queued before its subdomain.
        $queued = Queue::pushed(RunPanelTransferJob::class)->map(fn ($job) => PanelTransfer::query()->find($job->transferId)->domain)->values()->all();
        $this->assertSame(['example.com', 'blog.example.com'], $queued);
    }

    public function test_a_pull_downloads_the_package_and_imports_it_here(): void
    {
        $admin = $this->user('admin');
        $connection = PanelConnection::query()->create(['user_id' => $admin->id, 'name' => 'old', 'base_url' => 'https://old.example.com', 'token' => 'dp-api-x', 'remote_scopes' => ['migration:read']]);
        $transfer = PanelTransfer::query()->create([
            'connection_id' => $connection->id, 'user_id' => $admin->id, 'direction' => 'pull', 'kind' => 'website',
            'domain' => 'shop.example.com', 'status' => 'queued', 'options' => ['site' => ['type' => 'main', 'owner_email' => 'owner@example.com']],
        ]);
        $bytes = 'zip-bytes-of-the-site';
        Http::fake([
            'old.example.com/api/v1/migration/exports' => Http::response(['data' => ['id' => 'e0e0e0e0-0000-4000-8000-000000000001', 'status' => 'queued']], 202),
            'old.example.com/api/v1/migration/exports/e0e0e0e0-0000-4000-8000-000000000001' => Http::sequence()
                ->push(['data' => ['status' => 'ready', 'size' => strlen($bytes), 'total' => 1, 'chunk_size' => PanelPackageStore::CHUNK_SIZE, 'sha256' => hash('sha256', $bytes), 'name' => 'shop.example.com.zip']])
                ->push(['message' => 'Export removed.']),
            'old.example.com/api/v1/migration/exports/e0e0e0e0-0000-4000-8000-000000000001/chunks/0' => Http::response($bytes, 200, ['X-Chunk-Sha256' => hash('sha256', $bytes)]),
        ]);
        $importer = Mockery::mock(PanelPackageImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(function ($actor, $kind, $path, $site) use ($admin, $bytes): bool {
            return $actor->id === $admin->id && $kind === 'website' && File::get($path) === $bytes
                && $site['domain'] === 'shop.example.com' && $site['owner_email'] === 'owner@example.com';
        })->andReturn(['message' => 'shop.example.com was created and its files and database copied.']);
        $this->app->instance(PanelPackageImporter::class, $importer);

        (new RunPanelTransferJob($transfer->id))->handle(app(PanelPackageStore::class), app(\App\Services\NotificationService::class));

        $transfer->refresh();
        $this->assertSame('completed', $transfer->status, (string) $transfer->message);
        $this->assertSame(100, $transfer->percent);
        $this->assertStringContainsString('was created', (string) $transfer->message);
        $this->assertDirectoryDoesNotExist(app(PanelPackageStore::class)->directory($transfer->id));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/exports/e0e0e0e0-0000-4000-8000-000000000001'));
    }

    public function test_a_push_uploads_the_package_and_waits_for_the_other_panel(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $owner = $this->user('general', ['email' => 'owner@example.com']);
        $this->website($owner, 'shop.example.com');
        $connection = PanelConnection::query()->create(['user_id' => $admin->id, 'name' => 'new', 'base_url' => 'https://new.example.com', 'token' => 'dp-api-x', 'remote_scopes' => ['migration:write']]);
        $transfer = PanelTransfer::query()->create(['connection_id' => $connection->id, 'user_id' => $admin->id, 'direction' => 'push', 'kind' => 'website', 'domain' => 'shop.example.com', 'status' => 'queued']);
        $builder = Mockery::mock(\App\Services\PanelLink\PanelPackageBuilder::class);
        $builder->shouldReceive('build')->once()->andReturnUsing(function ($actor, $kind, $website, $paths, $path): array {
            File::put($path, 'zip-bytes');

            return ['path' => $path, 'extension' => 'zip', 'has_database' => true, 'warning' => '', 'name' => 'shop.example.com.zip'];
        });
        $this->app->instance(\App\Services\PanelLink\PanelPackageBuilder::class, $builder);
        $id = 'a0a0a0a0-0000-4000-8000-000000000002';
        Http::fake([
            'new.example.com/api/v1/migration/uploads' => Http::response(['data' => ['id' => $id, 'status' => 'uploading']], 201),
            "new.example.com/api/v1/migration/uploads/{$id}/chunks/0" => Http::response(['data' => ['index' => 0]]),
            "new.example.com/api/v1/migration/uploads/{$id}/complete" => Http::response(['data' => ['status' => 'queued']], 202),
            "new.example.com/api/v1/migration/uploads/{$id}" => Http::sequence()
                ->push(['data' => ['status' => 'importing', 'stage' => 'restoring']])
                ->push(['data' => ['status' => 'completed', 'message' => 'shop.example.com was created and its files and database copied.']]),
        ]);
        $run = fn () => (new RunPanelTransferJob($transfer->id))->handle(app(PanelPackageStore::class), app(\App\Services\NotificationService::class));

        $run();
        $this->assertSame('running', $transfer->refresh()->status);
        $this->assertSame('The other panel is restoring files and database', $transfer->message);
        Queue::assertPushed(RunPanelTransferJob::class, 1);
        $run();

        $this->assertSame('completed', $transfer->refresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/migration/uploads') && $request['site']['owner_email'] === 'owner@example.com'
            && $request['sha256'] === hash('sha256', 'zip-bytes') && $request['kind'] === 'website');
    }

    public function test_a_cancelled_transfer_stops(): void
    {
        $admin = $this->user('admin');
        $connection = PanelConnection::query()->create(['user_id' => $admin->id, 'name' => 'old', 'base_url' => 'https://old.example.com', 'token' => 'dp-api-x', 'remote_scopes' => ['migration:read']]);
        $transfer = PanelTransfer::query()->create(['connection_id' => $connection->id, 'user_id' => $admin->id, 'direction' => 'pull', 'kind' => 'panel', 'status' => 'failed', 'message' => 'Cancelled']);
        Http::fake();

        (new RunPanelTransferJob($transfer->id))->handle(app(PanelPackageStore::class), app(\App\Services\NotificationService::class));

        Http::assertNothingSent();
        $this->assertSame('failed', $transfer->refresh()->status);
    }

    public function test_addresses_are_normalized(): void
    {
        $this->assertSame('https://panel.example.com', PanelLinkClient::normalizeUrl('panel.example.com'));
        $this->assertSame('https://panel.example.com:2083', PanelLinkClient::normalizeUrl('https://Panel.Example.com:2083/cpsess'.str_repeat('a', 64).'/migrations'));
        $this->assertSame('http://10.0.0.5', PanelLinkClient::normalizeUrl('http://10.0.0.5/'));
    }
}
