<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Bandwidth\BandwidthLimitService;
use App\Services\EdgeGatewayReloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BandwidthLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var list<array<int, string>> */
    private array $reloaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dpanel-bw-'.Str::random(8);
        File::ensureDirectoryExists($this->dir);
        config(['serverpanel.bandwidth_directory' => $this->dir]);
        $this->mock(EdgeGatewayReloader::class)->shouldReceive('reloadDomains')
            ->andReturnUsing(function (array $domains) {
                $this->reloaded[] = $domains;

                return true;
            });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function account(string $action, int $limitGb = 1, string $state = 'active'): array
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'P', 'slug' => 'p', 'max_storage_mb' => 100, 'max_mailboxes' => 1,
            'max_bandwidth_gb' => $limitGb, 'over_bandwidth_action' => $action,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        $site = Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id, 'serving_state' => $state,
        ]);
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'www.shop.test', 'hostname' => 'www.shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'alias',
            'ssl_mode' => 'none', 'parent_id' => $site->id,
        ]);

        return [$user, $site];
    }

    private function traffic(string $month, int $mainBytes, int $aliasBytes = 0): void
    {
        File::put("{$this->dir}/{$month}.json", json_encode([
            'shop.test' => ['requests' => 1, 'upload_bytes' => 0, 'download_bytes' => $mainBytes],
            'www.shop.test' => ['requests' => 1, 'upload_bytes' => $aliasBytes, 'download_bytes' => 0],
        ]));
    }

    public function test_notify_tells_once_a_month_and_keeps_the_site_up(): void
    {
        [$user, $site] = $this->account('notify');
        $this->traffic('2026-11', 1024 ** 3);
        $limits = app(BandwidthLimitService::class);

        $this->assertTrue($limits->check($user, '2026-11'));
        $this->assertTrue($limits->check($user->fresh(), '2026-11'));

        $this->assertSame(1, Notification::query()->where('type', 'bandwidth_limit')->count());
        $this->assertSame('active', $site->fresh()->serving_state);
    }

    public function test_alias_traffic_counts_toward_the_limit(): void
    {
        [$user] = $this->account('notify');
        $this->traffic('2026-11', 600 * 1024 ** 2, 600 * 1024 ** 2);

        $this->assertTrue(app(BandwidthLimitService::class)->check($user, '2026-11'));
    }

    public function test_block_pauses_the_sites_until_the_month_resets(): void
    {
        [$user, $site] = $this->account('block');
        $this->traffic('2026-11', 2 * 1024 ** 3);
        $limits = app(BandwidthLimitService::class);

        $this->reloaded = [];
        $limits->check($user, '2026-11');
        $this->assertSame('over_limit', $site->fresh()->serving_state);
        $this->assertContains(['shop.test'], $this->reloaded);

        $this->assertFalse($limits->check($user->fresh(), '2026-12'));
        $this->assertSame('active', $site->fresh()->serving_state);
    }

    public function test_block_leaves_a_suspended_site_suspended(): void
    {
        [$user, $site] = $this->account('block', state: 'suspended');
        $this->traffic('2026-11', 2 * 1024 ** 3);
        $limits = app(BandwidthLimitService::class);

        $limits->check($user, '2026-11');
        $limits->check($user->fresh(), '2026-12');

        $this->assertSame('suspended', $site->fresh()->serving_state);
    }

    public function test_zero_means_no_limit(): void
    {
        [$user, $site] = $this->account('block', limitGb: 0);
        $this->traffic('2026-11', 50 * 1024 ** 3);

        $this->assertNull(app(BandwidthLimitService::class)->check($user, '2026-11'));
        $this->assertSame('active', $site->fresh()->serving_state);
    }
}
