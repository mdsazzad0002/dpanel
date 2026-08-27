<?php

namespace Tests\Feature;

use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function panelSession(User $user, string $token): PanelSession
    {
        return PanelSession::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'cookie_hash' => hash('sha256', 'test-cookie'),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'phpunit'),
            'expires_at' => now()->addHour(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_admin_can_impersonate_a_user_and_return_to_admin(): void
    {
        $admin = User::factory()->create(['name' => 'Panel Admin']);
        $target = User::factory()->create(['name' => 'Customer']);
        Role::findOrCreate('admin');
        Role::findOrCreate('general');
        $admin->assignRole('admin');
        $target->assignRole('general');
        $token = bin2hex(random_bytes(32));
        $panelSession = $this->panelSession($admin, $token);

        $response = $this->actingAs($admin)
            ->withSession(['panel_session_token' => $token])
            ->post("/cpsess{$token}/users/manage/{$target->id}/impersonate");

        $response->assertRedirect(route('dashboard', ['token' => $token]));
        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('impersonation.admin_id'));
        $this->assertSame($target->id, $panelSession->fresh()->user_id);

        $response = $this->post("/cpsess{$token}/impersonation/stop");

        $response->assertRedirect(route('users.manage', ['token' => $token]));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('impersonation.admin_id'));
        $this->assertSame($admin->id, $panelSession->fresh()->user_id);
    }

    public function test_non_admin_cannot_impersonate_another_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        Role::findOrCreate('reseller');
        $actor->assignRole('reseller');
        $token = bin2hex(random_bytes(32));
        $this->panelSession($actor, $token);

        $this->actingAs($actor)
            ->withSession(['panel_session_token' => $token])
            ->post("/cpsess{$token}/users/manage/{$target->id}/impersonate")
            ->assertForbidden();

        $this->assertAuthenticatedAs($actor);
    }

    public function test_admin_cannot_impersonate_a_suspended_user(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create(['is_suspended' => true]);
        Role::findOrCreate('admin');
        $admin->assignRole('admin');
        $token = bin2hex(random_bytes(32));
        $this->panelSession($admin, $token);

        $this->actingAs($admin)
            ->withSession(['panel_session_token' => $token])
            ->from("/cpsess{$token}/users/manage")
            ->post("/cpsess{$token}/users/manage/{$target->id}/impersonate")
            ->assertRedirect("/cpsess{$token}/users/manage")
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($admin);
    }
}
