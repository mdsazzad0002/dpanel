<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPhpPoolAlertsCommand;
use App\Models\Notification;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Platform spec section 9: a site whose PHP keeps crashing is reported. */
class PhpPoolAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        Cache::forget(SyncPhpPoolAlertsCommand::LAST_SEEN);
    }

    private function website(string $owner, User $user): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => "{$owner}.test", 'hostname' => "{$owner}.test",
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
    }

    public function test_each_new_alert_notifies_the_sites_owner_once(): void
    {
        $user = User::factory()->create();
        $website = $this->website('shop', $user);
        $now = (int) floor(microtime(true) * 1000);
        Http::fake(['drust.test/php-pool/alerts*' => Http::sequence()
            ->push(['success' => true, 'alerts' => [
                ['id' => $now - 3_600_000, 'site' => 'shop', 'crashes' => 5, 'window_seconds' => 60],
                ['id' => $now, 'site' => 'shop', 'crashes' => 5, 'window_seconds' => 60],
            ]])
            ->push(['success' => true, 'alerts' => []]),
        ]);

        $this->artisan('php:pool-alerts')->assertSuccessful();
        $this->artisan('php:pool-alerts')->assertSuccessful();

        // The hour-old alert was there before the panel looked: history.
        $notification = Notification::query()->where('type', 'php_pool')->sole();
        $this->assertSame($user->id, $notification->assigned_user_id);
        $this->assertSame($website->id, $notification->subject_id);
        $this->assertSame('failed', $notification->status);
        $this->assertStringContainsString('5 times within 60 seconds', $notification->message);
        $this->assertSame($now, Cache::get(SyncPhpPoolAlertsCommand::LAST_SEEN));
        Http::assertSent(fn (Request $request) => $request['after'] === $now && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_an_unknown_account_still_reaches_the_admins_and_drust_down_is_quiet(): void
    {
        Cache::forever(SyncPhpPoolAlertsCommand::LAST_SEEN, 10);
        Http::fake(['drust.test/php-pool/alerts*' => Http::sequence()
            ->push(['success' => true, 'alerts' => [['id' => 11, 'site' => 'gone', 'crashes' => 5, 'window_seconds' => 60]]])
            ->push('down', 502),
        ]);

        $this->artisan('php:pool-alerts')->assertSuccessful();
        $this->artisan('php:pool-alerts')->assertSuccessful();

        $notification = Notification::query()->where('type', 'php_pool')->sole();
        $this->assertNull($notification->assigned_user_id);
        $this->assertSame(11, Cache::get(SyncPhpPoolAlertsCommand::LAST_SEEN));
    }
}
