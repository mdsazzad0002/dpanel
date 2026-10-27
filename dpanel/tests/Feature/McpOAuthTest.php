<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private string $panelToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panelToken = str_repeat('a', 64);
    }

    public function test_discovery_metadata_is_published(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/api/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', url('/'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_full_authorization_code_flow_with_pkce_and_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientId = $this->registerClient();
        $verifier = str_repeat('v', 64);

        // 1. The client sends the browser to /oauth/authorize; a signed-in admin is sent to the consent screen.
        $authorize = $this->actingAs($admin)
            ->withSession(['panel_session_token' => $this->panelToken])
            ->get('/oauth/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => self::REDIRECT,
                'code_challenge' => $this->challenge($verifier),
                'code_challenge_method' => 'S256',
                'state' => 'xyz',
                'scope' => 'mcp:read mcp:full',
                'resource' => url('/api/mcp'),
            ]));

        $authorize->assertRedirect();
        $this->assertMatchesRegularExpression('#/cpsess'.$this->panelToken.'/settings/mcp/authorize/([A-Za-z0-9]{40})$#', $authorize->headers->get('Location'));
        $requestId = basename($authorize->headers->get('Location'));

        // 2. The admin approves.
        $decision = $this->panel($admin)->postJson("/cpsess{$this->panelToken}/settings/mcp/authorize/{$requestId}", [
            'approve' => true,
            'scope' => 'full',
        ])->assertOk();

        $redirect = $decision->json('redirect');
        $this->assertStringStartsWith(self::REDIRECT.'?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        // 3. A wrong verifier fails, and burns the code.
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => str_repeat('w', 64),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // Run the flow again for a fresh code.
        $code = $this->approveAgain($admin, $clientId, $verifier);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('scope', 'mcp:read mcp:full');

        // The code is single-use.
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'code_verifier' => $verifier,
        ])->assertStatus(400);

        // 4. The access token works on the MCP endpoint.
        $this->mcp($tokens->json('access_token'))->assertOk()->assertJsonPath('result', []);

        $grant = McpAccessToken::query()->firstOrFail();
        $this->assertSame('full', $grant->scope);
        $this->assertTrue($grant->isOAuth());

        // 5. Refreshing rotates both tokens.
        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->json('refresh_token'),
            'client_id' => $clientId,
        ])->assertOk();

        $this->mcp($tokens->json('access_token'))->assertStatus(401);
        $this->mcp($refreshed->json('access_token'))->assertOk();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens->json('refresh_token'), 'client_id' => $clientId,
        ])->assertStatus(400);

        // 6. Revocation ends the grant.
        $this->post('/oauth/revoke', ['token' => $refreshed->json('refresh_token'), 'client_id' => $clientId])->assertOk();
        $this->mcp($refreshed->json('access_token'))->assertStatus(401);
    }

    public function test_signed_out_user_is_sent_to_login_and_returns_to_consent(): void
    {
        $clientId = $this->registerClient();

        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $this->challenge(str_repeat('v', 64)),
            'code_challenge_method' => 'S256',
        ]))->assertRedirect(route('login'))->assertSessionHas('panel.last_path');

        $this->assertStringStartsWith('/settings/mcp/authorize/', session('panel.last_path'));
    }

    public function test_authorize_rejects_bad_requests(): void
    {
        $clientId = $this->registerClient();

        // Unregistered redirect: never redirect there, show an error page.
        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://evil.test/cb',
            'code_challenge' => $this->challenge(str_repeat('v', 64)), 'code_challenge_method' => 'S256',
        ]))->assertStatus(400);

        // Missing PKCE: error goes back to the client.
        $response = $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'state' => 's',
        ]));
        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
    }

    public function test_registration_rejects_unsafe_redirect_uris(): void
    {
        foreach (['http://evil.test/cb', 'javascript:alert(1)', 'https://x.test/cb#frag'] as $uri) {
            $this->postJson('/oauth/register', ['client_name' => 'x', 'redirect_uris' => [$uri]])
                ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }

        $this->postJson('/oauth/register', ['redirect_uris' => ['http://127.0.0.1:33418/callback']])->assertCreated();
        $this->postJson('/oauth/register', ['redirect_uris' => ['cursor://anysphere.cursor-retrieval/oauth/callback']])->assertCreated();
    }

    public function test_confidential_clients_must_authenticate(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Secret app',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'client_secret_post',
        ])->assertCreated();

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => $response->json('client_id'),
        ])->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => 'x',
            'client_id' => $response->json('client_id'), 'client_secret' => $response->json('client_secret'),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_denying_redirects_with_access_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requestId = $this->startAuthorization($admin, $this->registerClient(), str_repeat('v', 64));

        $redirect = $this->panel($admin)->postJson("/cpsess{$this->panelToken}/settings/mcp/authorize/{$requestId}", ['approve' => false])
            ->assertOk()->json('redirect');

        $this->assertStringContainsString('error=access_denied', $redirect);
    }

    public function test_non_admin_cannot_approve(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requestId = $this->startAuthorization($admin, $this->registerClient(), str_repeat('v', 64));
        $user = User::factory()->create(['role' => 'general']);

        $this->panel($user)->postJson("/cpsess{$this->panelToken}/settings/mcp/authorize/{$requestId}", ['approve' => true, 'scope' => 'full'])
            ->assertForbidden();
    }

    private function registerClient(): string
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    private function startAuthorization(User $admin, string $clientId, string $verifier): string
    {
        $authorize = $this->actingAs($admin)
            ->withSession(['panel_session_token' => $this->panelToken])
            ->get('/oauth/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => self::REDIRECT,
                'code_challenge' => $this->challenge($verifier),
                'code_challenge_method' => 'S256',
            ]));

        return basename((string) $authorize->headers->get('Location'));
    }

    private function approveAgain(User $admin, string $clientId, string $verifier): string
    {
        $requestId = $this->startAuthorization($admin, $clientId, $verifier);
        $redirect = $this->panel($admin)->postJson("/cpsess{$this->panelToken}/settings/mcp/authorize/{$requestId}", [
            'approve' => true, 'scope' => 'full',
        ])->json('redirect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return $query['code'];
    }

    private function panel(User $user): static
    {
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->withSession(['panel_session_token' => $this->panelToken])
            ->actingAs($user);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function mcp(string $accessToken): TestResponse
    {
        // A fresh request: forget the session user from the panel steps.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$accessToken])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    }
}
