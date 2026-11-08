<?php

namespace Tests\Feature;

use App\Models\AccountUsageSample;
use App\Models\Notification;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiskUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.execution_api_base_url' => 'http://drust.test']);
    }

    /** @param array<string, int> $usage per system account */
    private function account(string $mode, array $usage, int $limitMb = 1000): User
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'P'.Str::random(4), 'slug' => Str::random(6),
            'max_storage_mb' => $limitMb, 'max_mailboxes' => 1, 'enforcement_mode' => $mode,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        foreach (array_keys($usage) as $owner) {
            Website::create([
                'id' => (string) Str::uuid(), 'domain' => "{$owner}.test", 'hostname' => "{$owner}.test",
                'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
                'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
                'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
            ]);
        }
        Http::fake(function ($request) use ($usage) {
            preg_match('#/accounts/([^/]+)/usage#', $request->url(), $m);

            return Http::response(['success' => true, 'disk_mb' => $usage[$m[1] ?? ''] ?? 0, 'disk_limit_level' => 'hard']);
        });

        return $user;
    }

    /** @return array<string, int> */
    private function limitsSent(): array
    {
        return Http::recorded(fn ($request) => $request->method() === 'PUT')
            ->mapWithKeys(fn ($pair) => [explode('/', $pair[0]->url())[4] => $pair[0]['disk_mb']])->all();
    }

    public function test_usage_is_summed_over_the_accounts_system_users_and_recorded(): void
    {
        $user = $this->account('report', ['shop' => 300, 'blog' => 200]);

        $this->artisan('accounts:disk-check')->assertSuccessful();

        $this->assertSame(500, AccountUsageSample::query()->where('user_id', $user->id)->value('disk_mb'));
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_report_mode_notifies_once_per_crossing_and_sets_no_limit(): void
    {
        $user = $this->account('report', ['shop' => 1200]);

        $this->artisan('accounts:disk-check')->assertSuccessful();
        $this->artisan('accounts:disk-check')->assertSuccessful();

        $this->assertSame(1, Notification::query()->where('type', 'disk_quota')->where('assigned_user_id', $user->id)->count());
        $this->assertSame(['shop' => 0], $this->limitsSent());
    }

    public function test_enforce_mode_lets_the_accounts_grow_only_by_what_is_left(): void
    {
        $this->account('enforce', ['shop' => 300, 'blog' => 200]);

        $this->artisan('accounts:disk-check')->assertSuccessful();

        // 500 MB left of 1000: each may take it, and the next check narrows it again.
        $this->assertSame(['shop' => 800, 'blog' => 700], $this->limitsSent());
    }

    public function test_enforce_mode_stops_writes_once_over(): void
    {
        $this->account('enforce', ['shop' => 1100]);

        $this->artisan('accounts:disk-check')->assertSuccessful();

        $this->assertSame(['shop' => 1100], $this->limitsSent());
    }

    public function test_new_plans_default_to_report(): void
    {
        $plan = PackagePlan::query()->create(['id' => (string) Str::uuid(), 'name' => 'N', 'slug' => 'n', 'max_storage_mb' => 1, 'max_mailboxes' => 1]);

        $this->assertSame('report', $plan->fresh()->enforcement_mode);
    }
}
