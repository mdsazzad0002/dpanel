<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\PackagePlan;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;
use App\Services\ResourceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Task P0: each test states what enforcement must do and fails until it does.
 * The `gap` group is left out of a normal run; see it with
 * `php artisan test --group=gap`. P1 and P2 remove a test from the group as they close its gap.
 */
#[Group('gap')]
class EnforcementGapTest extends TestCase
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

    private function suspendFromThePanel(User $user): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EnsurePanelSessionIsValid::class)
            ->actingAs($this->admin())
            ->withSession(['panel_session_token' => $this->token])
            ->patch("/cpsess{$this->token}/users/manage/{$user->id}/suspension", ['suspend' => true])
            ->assertRedirect();
    }

    public function test_a_suspended_user_with_a_live_session_is_turned_away(): void
    {
        $user = User::factory()->create(['is_suspended' => true, 'suspended_at' => now()]);

        $response = $this->withoutMiddleware(\App\Http\Middleware\EnsurePanelSessionIsValid::class)
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token])
            ->get("/cpsess{$this->token}/dashboard");

        $this->assertNotSame(200, $response->getStatusCode());
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
        $website = Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);

        $this->suspendFromThePanel($user);

        $this->assertSame('suspended', $website->fresh()->serving_state);
    }

    public function test_the_storage_limit_counts_all_of_a_users_mailboxes(): void
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'Small', 'slug' => 'small', 'max_storage_mb' => 100, 'max_mailboxes' => 10,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
        Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'mailbox' => 'a', 'email' => 'a@shop.test',
            'password' => 'x', 'quota_mb' => 80, 'status' => 'active',
        ]);

        $this->expectException(ValidationException::class);
        app(ResourceQuotaService::class)->assertMailboxAllowed($user, 80);
    }
}
