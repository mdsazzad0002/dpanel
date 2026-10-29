<?php

namespace Tests\Feature;

use App\Http\Controllers\SitePushController;
use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Jobs\PushWebsiteToLiveJob;
use App\Jobs\RestorePushedWebsiteJob;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePushToken;
use App\Services\Backup\ClonePackageBuilder;
use App\Services\Backup\CloneShareJobStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SitePushTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    // Keep these tests' files out of the real storage/: run as a login user,
    // they left root dirs (storage/app/migrations/site-push, restore-progress)
    // the www-data worker then couldn't create subdirectories in.
    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/dpanel-test-storage-'.bin2hex(random_bytes(6));
        foreach (['app', 'framework/views', 'framework/cache/data', 'logs'] as $directory) {
            File::ensureDirectoryExists($this->storageRoot.'/'.$directory);
        }
        $this->app->useStoragePath($this->storageRoot);
        config(['view.compiled' => $this->storageRoot.'/framework/views']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_host_url_is_reduced_to_scheme_host_and_port(): void
    {
        $normalize = (new \ReflectionClass(SitePushController::class))->getMethod('normalizeHostUrl');
        $controller = app(SitePushController::class);

        $this->assertSame('https://panel.example.com', $normalize->invoke($controller, 'panel.example.com'));
        $this->assertSame('https://panel.example.com:2083', $normalize->invoke($controller, 'https://Panel.Example.com:2083/cpsess'.str_repeat('a', 64).'/websites/x'));
        $this->assertSame('http://203.0.113.5:8080', $normalize->invoke($controller, 'http://203.0.113.5:8080/'));
        $this->assertNull($normalize->invoke($controller, 'https://bad host'));
    }

    public function test_push_to_another_site_on_the_same_server_is_allowed_but_not_onto_itself(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->assignRole('admin');
        $source = $this->website(['assigned_user_id' => $user->id]);
        $target = $this->website(['assigned_user_id' => $user->id]);
        [, $targetPlain] = WebsitePushToken::issue($target, $user, 'Same server', now()->addDay());
        [, $sourcePlain] = WebsitePushToken::issue($source, $user, 'Self', now()->addDay());

        $push = function (string $plain) use ($user, $source) {
            $request = Request::create('/', 'POST', ['host_url' => 'https://this-server.test', 'push_token' => $plain, 'confirm_domain' => 'Target.Test']);
            $request->setUserResolver(fn () => $user);

            return app(SitePushController::class)->push($request, 'token', (string) $source->id);
        };

        $this->assertSame(200, $push($targetPlain)->getStatusCode());
        Queue::assertPushed(PushWebsiteToLiveJob::class, fn (PushWebsiteToLiveJob $job) => $job->liveUrl === 'https://this-server.test' && $job->expectedDomain === 'target.test');
        $this->assertSame(422, $push($sourcePlain)->getStatusCode());
    }

    public function test_connect_shows_what_the_token_would_overwrite(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $source = $this->website(['assigned_user_id' => $user->id]);
        Http::fake(['https://live.example.com/api/site-push/v1/handshake' => Http::response([
            'ok' => true, 'domain' => 'shop.example.com', 'server' => 'live-1', 'token_name' => 'Laptop', 'chunk_size' => 5242880, 'max_chunks' => 10000,
        ])]);
        $request = Request::create('/', 'POST', ['host_url' => 'live.example.com/cpsess'.str_repeat('a', 64), 'push_token' => 'dp-push-abc']);
        $request->setUserResolver(fn () => $user);

        $data = app(SitePushController::class)->connect($request, 'token', (string) $source->id)->getData(true);

        $this->assertSame(['https://live.example.com', 'shop.example.com', 'live-1', 'Laptop', $source->domain], [
            $data['host_url'], $data['live_domain'], $data['server'], $data['token_name'], $data['source_domain'],
        ]);
    }

    public function test_push_stops_if_the_token_now_points_at_another_domain(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $website = $this->website(['assigned_user_id' => $user->id]);
        $builder = $this->mock(ClonePackageBuilder::class);
        $builder->shouldNotReceive('build');
        Http::fake(['*' => Http::response(['ok' => true, 'domain' => 'other.example.com', 'chunk_size' => 5242880, 'max_chunks' => 10000])]);

        $pushId = (string) Str::uuid();
        (new PushWebsiteToLiveJob($pushId, (string) $website->id, $user->id, 'https://live.example.com', Crypt::encryptString('dp-push-x'), 'shop.example.com'))
            ->handle($builder, app(\App\Services\NotificationService::class));

        $status = CloneShareJobStatus::get($pushId);
        $this->assertSame('failed', $status['stage']);
        $this->assertSame('connecting', $status['failed_stage']);
        $this->assertStringContainsString('other.example.com', $status['message']);
    }

    public function test_api_rejects_missing_revoked_and_system_tokens(): void
    {
        $this->postJson('/api/site-push/v1/handshake')->assertStatus(401);

        [$token, $plain] = WebsitePushToken::issue($this->website(), null, 'Laptop', now()->addDay());
        $this->withToken($plain)->postJson('/api/site-push/v1/handshake')->assertOk()->assertJson(['ok' => true]);

        $token->update(['revoked_at' => now()]);
        $this->withToken($plain)->postJson('/api/site-push/v1/handshake')->assertStatus(401);

        [, $systemPlain] = WebsitePushToken::issue($this->website(['site_owner' => 'system']), null, 'Panel', now()->addDay());
        $this->withToken($systemPlain)->postJson('/api/site-push/v1/handshake')->assertStatus(403);
    }

    public function test_chunked_upload_is_verified_and_queues_the_restore(): void
    {
        Queue::fake();
        [$token, $plain] = WebsitePushToken::issue($this->website(), null, 'Laptop', now()->addDay());
        $package = random_bytes(1024);

        $uploadId = $this->withToken($plain)->postJson('/api/site-push/v1/uploads', [
            'size' => strlen($package), 'total' => 1, 'sha256' => hash('sha256', $package), 'source_domain' => 'site.test',
        ])->assertOk()->json('upload_id');

        // A different token can't touch this upload.
        [, $otherPlain] = WebsitePushToken::issue($this->website(), null, 'Other', now()->addDay());
        $this->withToken($otherPlain)->postJson("/api/site-push/v1/uploads/{$uploadId}/complete")->assertStatus(404);

        $this->withToken($plain)->post("/api/site-push/v1/uploads/{$uploadId}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk-0.part', $package),
            'sha256' => hash('sha256', $package),
        ], ['Accept' => 'application/json'])->assertOk();

        try {
            $this->withToken($plain)->postJson("/api/site-push/v1/uploads/{$uploadId}/complete")->assertOk();

            Queue::assertPushed(RestorePushedWebsiteJob::class, fn (RestorePushedWebsiteJob $job) => $job->tokenId === $token->id
                && hash_file('sha256', $job->packagePath) === hash('sha256', $package));
            $this->withToken($plain)->getJson("/api/site-push/v1/uploads/{$uploadId}")->assertOk()->assertJson(['stage' => 'queued']);
            $this->withToken($otherPlain)->getJson("/api/site-push/v1/uploads/{$uploadId}")->assertStatus(404);
        } finally {
            File::deleteDirectory(storage_path('app/migrations/site-push/'.$uploadId));
        }
    }

    public function test_corrupted_package_is_rejected(): void
    {
        Queue::fake();
        [, $plain] = WebsitePushToken::issue($this->website(), null, 'Laptop', now()->addDay());
        $uploadId = $this->withToken($plain)->postJson('/api/site-push/v1/uploads', [
            'size' => 4, 'total' => 1, 'sha256' => hash('sha256', 'good'),
        ])->json('upload_id');
        $this->withToken($plain)->post("/api/site-push/v1/uploads/{$uploadId}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk-0.part', 'evil'),
        ], ['Accept' => 'application/json'])->assertOk();

        try {
            $this->withToken($plain)->postJson("/api/site-push/v1/uploads/{$uploadId}/complete")->assertStatus(422);
            Queue::assertNotPushed(RestorePushedWebsiteJob::class);
        } finally {
            File::deleteDirectory(storage_path('app/migrations/site-push/'.$uploadId));
        }
    }

    public function test_local_job_uploads_the_package_and_mirrors_the_live_result(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $website = $this->website(['assigned_user_id' => $user->id]);
        $packageBytes = random_bytes(3000);

        $builder = $this->mock(ClonePackageBuilder::class);
        $builder->shouldReceive('build')->once()->andReturnUsing(function ($u, $w, string $path) use ($packageBytes) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $packageBytes);

            return ['path' => $path, 'has_database' => false, 'warning' => ''];
        });

        $received = '';
        Http::fake(function ($request) use (&$received) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/handshake') => Http::response(['ok' => true, 'domain' => 'live.example.com', 'chunk_size' => 1024 * 1024, 'max_chunks' => 10000]),
                str_ends_with($path, '/uploads') => Http::response(['ok' => true, 'upload_id' => (string) Str::uuid()]),
                str_contains($path, '/chunks/') => (function () use ($request, &$received) {
                    foreach ($request->data() as $part) {
                        if (($part['name'] ?? '') === 'chunk') {
                            $received .= $part['contents'];
                        }
                    }

                    return Http::response(['ok' => true]);
                })(),
                str_ends_with($path, '/complete') => Http::response(['ok' => true]),
                default => Http::response(['ok' => true, 'stage' => 'completed']),
            };
        });

        $pushId = (string) Str::uuid();
        (new PushWebsiteToLiveJob($pushId, (string) $website->id, $user->id, 'https://live.example.com', Crypt::encryptString('dp-push-x')))
            ->handle($builder, app(\App\Services\NotificationService::class));

        $this->assertSame($packageBytes, $received);
        $this->assertSame('completed', CloneShareJobStatus::get($pushId)['stage']);
        $this->assertFileDoesNotExist(storage_path('app/backups/clone-share/push-'.$pushId.'.zip'));
    }

    public function test_a_failure_right_after_packaging_still_removes_the_package(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $website = $this->website(['assigned_user_id' => $user->id]);
        $pushId = (string) Str::uuid();
        $packagePath = storage_path('app/backups/clone-share/push-'.$pushId.'.zip');
        $builder = $this->mock(ClonePackageBuilder::class);
        $builder->shouldReceive('build')->once()->andReturnUsing(function ($u, $w, string $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'pkg');

            return ['path' => $path, 'has_database' => false, 'warning' => ''];
        });
        Http::fake(function ($request) {
            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/handshake')
                ? Http::response(['ok' => true, 'domain' => 'live.example.com', 'chunk_size' => 1048576, 'max_chunks' => 10000])
                : Http::response(['ok' => false, 'message' => 'mkdir(): Permission denied'], 500);
        });

        (new PushWebsiteToLiveJob($pushId, (string) $website->id, $user->id, 'https://live.example.com', Crypt::encryptString('dp-push-x')))
            ->handle($builder, app(\App\Services\NotificationService::class));

        $this->assertSame('failed', CloneShareJobStatus::get($pushId)['stage']);
        $this->assertFileDoesNotExist($packagePath);
    }

    public function test_waiting_for_the_live_restore_frees_the_worker(): void
    {
        // Same-server push: the live restore needs this same heavy worker, so
        // the push job must requeue itself rather than sleep-poll.
        Queue::fake();
        $user = User::factory()->create();
        $user->assignRole('admin');
        $website = $this->website(['assigned_user_id' => $user->id]);
        $pushId = (string) Str::uuid();
        Cache::put(PushWebsiteToLiveJob::stateKey($pushId), [
            'package_path' => '', 'upload_id' => (string) Str::uuid(), 'size' => 1, 'chunk_size' => 1,
            'total' => 1, 'next_index' => 1, 'completed' => true, 'live_domain' => 'live.example.com', 'warning' => '',
        ], now()->addHour());
        Http::fake(['*' => Http::response(['ok' => true, 'stage' => 'queued'])]);

        $started = microtime(true);
        (new PushWebsiteToLiveJob($pushId, (string) $website->id, $user->id, 'https://live.example.com', Crypt::encryptString('dp-push-x')))
            ->handle($this->mock(ClonePackageBuilder::class), app(\App\Services\NotificationService::class));

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertSame('remote_queued', CloneShareJobStatus::get($pushId)['stage']);
        Queue::assertPushed(PushWebsiteToLiveJob::class, fn (PushWebsiteToLiveJob $job) => $job->pushId === $pushId && $job->delay !== null);
    }

    public function test_page_shows_this_panels_host_url(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $website = $this->website();

        $session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $session]);

        $this->actingAs($admin)
            ->get(route('websites.push-to-live.index', ['token' => $session, 'id' => $website->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Websites/PushToLive')
                ->where('panelHostUrl', rtrim(url('/'), '/')));
    }

    public function test_package_is_built_by_drust_in_one_call(): void
    {
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 't']);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $website = $this->website(['assigned_user_id' => $user->id]);
        $path = storage_path('app/backups/clone-share/test-'.Str::random(8).'.zip');
        Http::fake(['http://drust.test/api/v1/website/clone-package' => function ($request) use ($path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'pkg');

            return Http::response(['success' => true, 'data' => ['has_database' => true, 'skipped' => 2, 'skipped_samples' => ['/a: denied']]]);
        }]);

        try {
            $result = app(ClonePackageBuilder::class)->build($user, $website, $path);

            $this->assertTrue($result['has_database']);
            $this->assertStringContainsString('2 file(s) could not be read', $result['warning']);
            Http::assertSent(fn ($request) => $request['zip_path'] === $path && $request['website']['site_owner'] === $website->site_owner);
        } finally {
            File::delete($path);
        }
    }

    private function website(array $overrides = []): Website
    {
        $owner = $overrides['site_owner'] ?? 'owner'.Str::lower(Str::random(6));

        return Website::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'domain' => Str::lower(Str::random(10)).'.test',
            'root_path' => '/home/'.$owner.'/public_html',
            'project_root' => '/home/'.$owner,
            'site_owner' => $owner,
            'php_version' => '8.3',
            'app_installer' => 'none',
            'wordpress_version' => 'latest',
            'enable_ssl' => false,
            'status' => 'active',
        ], $overrides));
    }
}
