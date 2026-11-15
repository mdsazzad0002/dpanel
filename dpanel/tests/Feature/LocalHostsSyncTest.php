<?php

namespace Tests\Feature;

use App\Jobs\SyncLocalHostsJob;
use App\Models\User;
use App\Models\Website;
use App\Services\Local\LocalHostsSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog F6: on a PC the hosts file follows the websites. */
class LocalHostsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dpanel.execution_api_base_url' => 'http://127.0.0.1:9500',
            'dpanel.execution_api_token' => 'secret',
        ]);
    }

    private function website(string $domain): Website
    {
        $owner = User::factory()->create();

        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    public function test_creating_renaming_and_deleting_a_site_on_a_pc_syncs_the_hosts_file(): void
    {
        config(['app.profile' => 'local']);
        Queue::fake();
        // Each step on its own: a job still waiting holds the unique lock,
        // and it reads the whole list when it runs.
        $pushedBy = function (callable $step): int {
            Cache::lock('laravel_unique_job:'.SyncLocalHostsJob::class.':')->forceRelease();
            $before = Queue::pushed(SyncLocalHostsJob::class)->count();
            $step();

            return Queue::pushed(SyncLocalHostsJob::class)->count() - $before;
        };

        $site = null;
        $this->assertSame(1, $pushedBy(function () use (&$site) {
            $site = $this->website('shop.test');
        }));
        $this->assertSame(0, $pushedBy(fn () => $site->update(['php_version' => '8.2'])));
        $this->assertSame(1, $pushedBy(fn () => $site->update(['domain' => 'store.test'])));
        $this->assertSame(1, $pushedBy(fn () => $site->delete()));
    }

    public function test_several_changes_at_once_ask_once(): void
    {
        config(['app.profile' => 'local']);
        Queue::fake();
        $this->website('a.test');
        $this->website('b.test');
        Queue::assertPushed(SyncLocalHostsJob::class, 1);
    }

    public function test_a_server_never_touches_the_hosts_file(): void
    {
        config(['app.profile' => 'server']);
        Queue::fake();
        Http::fake();

        $this->website('shop.test')->delete();
        (new SyncLocalHostsJob)->handle(app(LocalHostsSync::class));

        Queue::assertNotPushed(SyncLocalHostsJob::class);
        Http::assertNothingSent();
    }

    public function test_the_job_sends_every_site_name_but_ip_addresses(): void
    {
        config(['app.profile' => 'local']);
        Queue::fake();
        $this->website('Shop.test');
        $this->website('blog.test');
        $this->website('127.0.0.1');
        Http::fake(['*/api/v1/local/hosts' => Http::response(['success' => true, 'outcome' => 'written', 'names' => 2])]);

        (new SyncLocalHostsJob)->handle(app(LocalHostsSync::class));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'http://127.0.0.1:9500/api/v1/local/hosts'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['names'] === ['blog.test', 'shop.test']);
    }

    public function test_a_declined_prompt_is_reported_not_thrown(): void
    {
        config(['app.profile' => 'local']);
        Http::fake(['*' => Http::response(['success' => false, 'outcome' => 'declined', 'names' => 0])]);

        $this->assertSame('declined', app(LocalHostsSync::class)->sync());
        $this->artisan('dpanel:hosts-sync')->assertFailed();
    }

    public function test_a_drust_failure_is_an_error(): void
    {
        config(['app.profile' => 'local']);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'hosts file is locked'], 500)]);

        $this->expectExceptionMessage('hosts file is locked');
        app(LocalHostsSync::class)->sync();
    }

    public function test_only_host_names_count(): void
    {
        foreach (['shop.test', 'a-b.c.test', 'localhost'] as $name) {
            $this->assertTrue(LocalHostsSync::isHostName($name), $name);
        }
        foreach (['', '127.0.0.1', '::1', '-a.test', 'a_b.test', 'a..test', "a.test\nb"] as $name) {
            $this->assertFalse(LocalHostsSync::isHostName($name), $name);
        }
    }
}
