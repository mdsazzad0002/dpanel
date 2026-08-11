<?php

namespace Tests\Feature;

use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSessionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_session_is_maintained_even_when_the_request_is_missing_the_proof_cookie(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));

        PanelSession::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'cookie_hash' => hash('sha256', 'existing-cookie'),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'phpunit'),
            'expires_at' => now()->addYear(),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        PanelSession::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'different-token'),
            'cookie_hash' => hash('sha256', ''),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'phpunit'),
            'expires_at' => now()->addYear(),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['panel_session_token' => $token])
            ->get('/confirm-password');

        $response->assertOk();
        $response->assertCookie((string) config('serverpanel.panel_cookie_name', 'panel_session_proof'));

        $session = PanelSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $this->assertNotNull($session);
        $this->assertNotSame(hash('sha256', ''), $session->cookie_hash);
        $this->assertSame(1, PanelSession::query()->where('user_id', $user->id)->count());
    }

    public function test_panel_session_expiry_slides_but_never_exceeds_the_absolute_limit(): void
    {
        config()->set('serverpanel.panel_inactivity_timeout', 10);
        config()->set('serverpanel.panel_token_lifetime', 60);
        config()->set('serverpanel.panel_token_refresh_threshold', 4);

        $session = new PanelSession(['expires_at' => now()->addMinutes(8)]);
        $session->created_at = now()->subMinutes(20);
        $this->assertTrue($session->refreshedExpiresAt()->between(
            now()->addMinutes(7),
            now()->addMinutes(8)->addSecond()
        ));

        $session->expires_at = now()->addMinutes(4);
        $this->assertTrue($session->refreshedExpiresAt()->between(
            now()->addMinutes(9),
            now()->addMinutes(10)->addSecond()
        ));

        $session->created_at = now()->subMinutes(55);
        $this->assertTrue($session->refreshedExpiresAt()->between(
            now()->addMinutes(4),
            now()->addMinutes(5)->addSecond()
        ));
    }
}
