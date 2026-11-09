<?php

namespace Tests\Feature;

use App\Models\AccountUsageSample;
use App\Models\Notification;
use App\Models\PackagePlan;
use App\Models\User;
use App\Services\UsageAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog P6: notices at 80% and 100% of a limit, and the usage page. */
class UsageAlertTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function record(User $user, float $used, float $limit = 100, string $period = ''): int
    {
        return app(UsageAlertService::class)->record($user, 'disk', $used, $limit, "{$used} MB of {$limit} MB", $period);
    }

    /** @return list<string> */
    private function titles(): array
    {
        return Notification::query()->pluck('title')->all();
    }

    public function test_each_level_is_sent_once_as_use_climbs(): void
    {
        $user = User::factory()->create(['name' => 'Shop']);

        $this->assertSame(0, $this->record($user, 50));
        $this->assertSame(80, $this->record($user, 85));
        $this->assertSame(0, $this->record($user, 90));
        $this->assertSame(100, $this->record($user, 100));
        $this->assertSame(0, $this->record($user, 120));

        $this->assertEqualsCanonicalizing(['Disk space 80% used', 'Disk space limit reached'], $this->titles());
        $warning = Notification::query()->where('status', 'warning')->sole();
        $this->assertSame('disk_quota', $warning->type);
        $this->assertSame('Shop uses 85 MB of 100 MB.', $warning->message);
        $this->assertSame($user->id, (int) $warning->assigned_user_id);
    }

    public function test_jumping_past_both_lines_sends_only_the_limit_notice(): void
    {
        $user = User::factory()->create();

        $this->assertSame(100, $this->record($user, 150));

        $this->assertSame(['Disk space limit reached'], $this->titles());
    }

    public function test_a_level_is_sent_again_only_after_use_falls_clearly_below_it(): void
    {
        $user = User::factory()->create();
        $this->record($user, 100);

        // Just under the line: hovering there must not send it again.
        $this->record($user, 95);
        $this->assertSame(0, $this->record($user, 100));

        // Well under 100 but still at 80: the mark drops to 80, so 100 can come again.
        $this->record($user, 85);
        $this->assertSame(100, $this->record($user, 101));

        // Back under 80 altogether: everything resets.
        $this->record($user, 40);
        $this->assertSame(80, $this->record($user, 82));
        $this->assertEqualsCanonicalizing(['Disk space limit reached', 'Disk space limit reached', 'Disk space 80% used'], $this->titles());
    }

    public function test_a_new_period_starts_over(): void
    {
        $user = User::factory()->create();
        $this->assertSame(100, $this->record($user, 100, period: '2026-10'));
        $this->assertSame(0, $this->record($user, 100, period: '2026-10'));

        $this->assertSame(80, $this->record($user, 80, period: '2026-11'));
    }

    public function test_no_limit_means_no_notice(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $this->record($user, 500, 0));
        $this->assertSame([], $this->titles());
    }

    private function packaged(?User $reseller = null): User
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'Plan '.Str::random(4), 'slug' => Str::random(6), 'max_storage_mb' => 1000,
            'max_mailboxes' => 1, 'max_bandwidth_gb' => 1, 'max_memory_mb' => 512,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id, 'reseller_id' => $reseller?->id]);
        $user->assignRole('general');

        return $user;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_the_usage_page_shows_the_newest_reading_against_the_package(): void
    {
        $user = $this->packaged();
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => now()->subHour(), 'disk_mb' => 100]);
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => now(), 'disk_mb' => 850]);
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => now(), 'memory_mb' => 256, 'cpu_percent' => 12.5]);
        app(UsageAlertService::class)->record($user, 'disk', 850, 1000, '850 MB of 1000 MB');

        $this->as($user)->get("/cpsess{$this->token}/usage")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Usage/Index')
                ->has('accounts', 1)
                ->where('accounts.0.resources.0.used', 850)
                ->where('accounts.0.resources.0.percent', 85)
                ->where('accounts.0.resources.1.limit', 1024)
                ->where('accounts.0.resources.2.used', 256)
                ->where('accounts.0.resources.2.percent', 50)
                ->where('accounts.0.resources.3.limit', null)
                ->where('accounts.0.alerts.disk', 80));
    }

    public function test_each_role_sees_only_its_own_accounts(): void
    {
        $reseller = User::factory()->create();
        $reseller->assignRole('reseller');
        $customer = $this->packaged($reseller);
        $stranger = $this->packaged();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->as($admin)->get("/cpsess{$this->token}/usage")->assertInertia(fn ($page) => $page->has('accounts', 2));
        $this->as($reseller)->get("/cpsess{$this->token}/usage")
            ->assertInertia(fn ($page) => $page->has('accounts', 2)->where('accounts', fn ($rows) => collect($rows)->pluck('id')->contains($customer->id) && ! collect($rows)->pluck('id')->contains($stranger->id)));

        $this->as($customer)->getJson("/cpsess{$this->token}/usage/{$stranger->id}/history")->assertNotFound();
        $this->as($reseller)->getJson("/cpsess{$this->token}/usage/{$customer->id}/history")->assertOk();
    }

    public function test_history_is_one_average_per_hour(): void
    {
        $user = $this->packaged();
        $hour = now()->subHours(2)->startOfHour();
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => $hour->copy()->addMinutes(5), 'memory_mb' => 100]);
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => $hour->copy()->addMinutes(10), 'memory_mb' => 300]);
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => now()->subDays(9), 'memory_mb' => 999]);

        $series = $this->as($user)->getJson("/cpsess{$this->token}/usage/{$user->id}/history")->assertOk()->json('series');

        $this->assertSame([[$hour->copy()->utc()->format('Y-m-d\TH:00:00\Z'), 200]], $series['memory_mb']);
        $this->assertSame([], $series['disk_mb']);
    }
}
