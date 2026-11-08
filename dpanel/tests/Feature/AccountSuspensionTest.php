<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWhmcsRequest;
use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\WhmcsAccount;
use App\Models\Website;
use App\Services\AccountSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin->refresh();
    }

    private function website(User $user, string $state = 'active'): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => Str::random(8).'.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id, 'serving_state' => $state,
        ]);
    }

    private function suspendFromThePanel(User $user): void
    {
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->actingAs($this->admin())
            ->withSession(['panel_session_token' => $this->token])
            ->patch("/cpsess{$this->token}/users/manage/{$user->id}/suspension", ['suspend' => true])
            ->assertRedirect();
    }

    public function test_a_suspended_user_with_a_live_session_is_turned_away(): void
    {
        $user = User::factory()->create(['is_suspended' => true, 'suspended_at' => now()]);

        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token])
            ->get("/cpsess{$this->token}/dashboard")
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_suspending_from_the_panel_revokes_their_sessions(): void
    {
        $user = User::factory()->create();
        $session = PanelSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'token'),
            'cookie_hash' => hash('sha256', 'cookie'),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'agent'),
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->suspendFromThePanel($user);

        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_suspending_closes_their_websites(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);

        $this->suspendFromThePanel($user);

        $this->assertSame('suspended', $website->fresh()->serving_state);
    }

    public function test_whmcs_takes_the_same_path(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);
        $account = WhmcsAccount::query()->create(['external_id' => 'svc-1', 'user_id' => $user->id, 'status' => 'active']);

        $this->withoutMiddleware(AuthenticateWhmcsRequest::class)
            ->postJson('/api/whmcs/v1/account/suspend', ['external_id' => $account->external_id])
            ->assertOk();

        $this->assertTrue($user->fresh()->is_suspended);
        $this->assertSame('suspended', $website->fresh()->serving_state);
    }

    public function test_unsuspending_reopens_only_what_suspension_closed(): void
    {
        $user = User::factory()->create();
        $closed = $this->website($user);
        $overLimit = $this->website($user, 'over_limit');
        $suspension = app(AccountSuspensionService::class);

        $suspension->suspend($user);
        $this->assertSame('over_limit', $overLimit->fresh()->serving_state);

        $suspension->unsuspend($user);
        $this->assertFalse($user->fresh()->is_suspended);
        $this->assertSame('active', $closed->fresh()->serving_state);
        $this->assertSame('over_limit', $overLimit->fresh()->serving_state);
    }

    public function test_suspending_a_reseller_suspends_their_customers(): void
    {
        $reseller = User::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $site = $this->website($customer);
        $other = User::factory()->create();

        app(AccountSuspensionService::class)->suspend($reseller);

        $this->assertTrue($customer->fresh()->is_suspended);
        $this->assertSame('suspended', $site->fresh()->serving_state);
        $this->assertFalse($other->fresh()->is_suspended);
    }

    public function test_unsuspending_a_reseller_keeps_a_customer_suspended_on_their_own(): void
    {
        $reseller = User::factory()->create();
        $withReseller = User::factory()->create(['reseller_id' => $reseller->id]);
        $onTheirOwn = User::factory()->create(['reseller_id' => $reseller->id]);
        $ownSite = $this->website($onTheirOwn);
        $suspension = app(AccountSuspensionService::class);

        $suspension->suspend($onTheirOwn);
        $suspension->suspend($reseller);
        $suspension->unsuspend($reseller);

        $this->assertFalse($withReseller->fresh()->is_suspended);
        $this->assertTrue($onTheirOwn->fresh()->is_suspended);
        $this->assertSame('suspended', $ownSite->fresh()->serving_state);
    }

    public function test_suspending_a_customer_again_makes_it_their_own_suspension(): void
    {
        $reseller = User::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $suspension = app(AccountSuspensionService::class);

        $suspension->suspend($reseller);
        $suspension->suspend($customer);
        $suspension->unsuspend($reseller);

        $this->assertTrue($customer->fresh()->is_suspended);
    }
}
