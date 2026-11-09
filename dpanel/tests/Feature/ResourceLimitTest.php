<?php

namespace Tests\Feature;

use App\Models\AccountUsageSample;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\ResourceLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog P4: a package's CPU, memory and process limit through drust. */
class ResourceLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        Http::fake([
            'drust.test/resource-limits/*/usage' => Http::response(['success' => true, 'cpu_percent' => 42.5, 'memory_mb' => 300, 'processes' => 7]),
            'drust.test/resource-limits/*' => Http::response(['success' => true, 'message' => 'ok']),
        ]);
    }

    private function account(int $cpu, int $memoryMb, int $processes = 0): User
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'P', 'slug' => Str::random(6), 'max_storage_mb' => 100, 'max_mailboxes' => 1,
            'max_cpu_percent' => $cpu, 'max_memory_mb' => $memoryMb, 'max_processes' => $processes,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        $this->site($user, 'shop.test', 'shop');

        return $user;
    }

    private function site(User $user, string $domain, string $owner): void
    {
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
    }

    /** @return list<Request> */
    private function sent(string $method): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === $method)->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_the_limit_and_site_users_go_to_drust_once_and_usage_is_sampled(): void
    {
        $user = $this->account(150, 1024, 200);
        $this->site($user, 'blog.test', 'blog');
        $service = app(ResourceLimitService::class);

        $usage = $service->check($user);

        $this->assertSame(['cpu_percent' => 42.5, 'memory_mb' => 300, 'processes' => 7], $usage);
        [$put] = $this->sent('PUT');
        $this->assertSame("http://drust.test/resource-limits/a{$user->id}", $put->url());
        $this->assertSame(['cpu_percent' => 150, 'memory_mb' => 1024, 'max_processes' => 200, 'owners' => ['blog', 'shop']], $put->data());
        $sample = AccountUsageSample::query()->where('user_id', $user->id)->sole();
        $this->assertSame(300, (int) $sample->memory_mb);
        $this->assertEqualsWithDelta(42.5, (float) $sample->cpu_percent, 0.01);

        $service->check($user->fresh());
        $this->assertCount(1, $this->sent('PUT'));

        // A new site user changes what drust must know.
        $this->site($user, 'shop2.test', 'shop2');
        $service->check($user->fresh());
        $this->assertCount(2, $this->sent('PUT'));
        $this->assertSame(['blog', 'shop', 'shop2'], $this->sent('PUT')[1]->data()['owners']);
    }

    public function test_a_limit_taken_off_the_package_is_removed_once(): void
    {
        $user = $this->account(0, 512);
        $service = app(ResourceLimitService::class);
        $service->check($user);

        $user->package->update(['max_memory_mb' => 0]);
        $this->assertNull($service->check($user->fresh()));
        $this->assertNull($user->fresh()->resource_limits_applied);
        $this->assertCount(1, $this->sent('DELETE'));

        $service->check($user->fresh());
        $this->assertCount(1, $this->sent('DELETE'));
    }

    public function test_an_account_without_a_limit_costs_no_request(): void
    {
        $this->account(0, 0);

        $result = app(ResourceLimitService::class)->checkAll();

        $this->assertSame(['checked' => 0, 'failed' => 0, 'unsupported' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_a_server_without_slices_is_counted_not_reported_as_a_failure(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['drust.test/*' => Http::response(['error' => 'unsupported', 'capability' => 'resource_limits'], 501)]);
        $user = $this->account(100, 0);

        $result = app(ResourceLimitService::class)->checkAll();

        $this->assertSame(['checked' => 0, 'failed' => 0, 'unsupported' => 1], $result);
        $this->assertNull($user->fresh()->resource_limits_applied);
    }

    public function test_the_command_runs_every_limited_account(): void
    {
        $this->account(100, 256);

        $this->artisan('accounts:resource-check')->expectsOutputToContain('Checked 1 limited account(s).')->assertSuccessful();
    }

    public function test_a_package_saves_its_resource_limits(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $plan = PackagePlan::query()->create(['id' => (string) Str::uuid(), 'name' => 'Q', 'slug' => 'q', 'max_storage_mb' => 100, 'max_mailboxes' => 1]);
        $token = str_repeat('a', 64);
        \Illuminate\Support\Facades\URL::defaults(['token' => $token]);

        $this->withoutMiddleware()->actingAs($admin)->withSession(['panel_session_token' => $token])
            ->patch("/cpsess{$token}/packages/{$plan->id}", [
                'name' => 'Q', 'max_storage_mb' => 100, 'max_mailboxes' => 1, 'max_websites' => 1, 'max_databases' => 1,
                'max_bandwidth_gb' => 0, 'max_cpu_percent' => 200, 'max_memory_mb' => 2048, 'max_processes' => 300,
            ])->assertRedirect();

        $plan->refresh();
        $this->assertSame([200, 2048, 300], [$plan->max_cpu_percent, $plan->max_memory_mb, $plan->max_processes]);
        $this->assertTrue($plan->hasResourceLimits());
    }

    public function test_memory_and_cpu_at_their_limit_send_a_notice(): void
    {
        // drust reports 300 MB and 42.5% CPU.
        $user = $this->account(44, 300);

        app(ResourceLimitService::class)->check($user);

        $titles = \App\Models\Notification::query()->where('assigned_user_id', $user->id)->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Memory limit reached', 'CPU limit reached'], $titles);
    }
}
