<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Http\Middleware\EnsurePanelSessionIsValid;
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
}
