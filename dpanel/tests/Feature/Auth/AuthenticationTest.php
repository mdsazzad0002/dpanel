<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_login_returns_to_the_path_remembered_at_logout(): void
    {
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class);

        $user = User::factory()->create();
        $oldToken = str_repeat('a', 64);

        $this->actingAs($user)
            ->withSession(['panel_session_token' => $oldToken])
            ->withHeader('referer', 'http://p.localhost/cpsess'.$oldToken.'/emails/create?secret=discarded')
            ->post('/logout')
            ->assertSessionHas('panel.last_path', '/emails/create');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $newToken = (string) session('panel_session_token');
        $this->assertNotSame($oldToken, $newToken);
        $response->assertRedirect('/cpsess'.$newToken.'/emails/create');
        $response->assertSessionMissing('panel.last_path');
    }

    public function test_login_screen_remains_visible_when_a_user_is_already_authenticated(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));

        $this->actingAs($user)
            ->withSession(['panel_session_token' => $token])
            ->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_new_login_forgets_an_existing_impersonation_session(): void
    {
        $previousUser = User::factory()->create();
        $newUser = User::factory()->create();
        $oldToken = bin2hex(random_bytes(32));

        $this->actingAs($previousUser)
            ->withSession([
                'panel_session_token' => $oldToken,
                'impersonation.admin_id' => 123,
                'impersonation.admin_name' => 'Old Admin',
            ])
            ->post('/login', [
                'email' => $newUser->email,
                'password' => 'password',
            ])
            ->assertSessionMissing('impersonation.admin_id')
            ->assertSessionMissing('impersonation.admin_name');

        $this->assertAuthenticatedAs($newUser);
    }
}
