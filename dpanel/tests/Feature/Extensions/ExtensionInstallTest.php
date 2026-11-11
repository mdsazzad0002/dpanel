<?php

namespace Tests\Feature\Extensions;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * Backlog U7: an extension acts through its own token on its own service
 * account, with no more than the admin approved, and loses it on disable
 * and uninstall.
 */
class ExtensionInstallTest extends ExtensionTestCase
{
    public function test_only_admins_reach_the_extensions_pages(): void
    {
        $reseller = User::factory()->create(['role' => 'reseller']);
        $general = User::factory()->create(['role' => 'general']);

        foreach ([$reseller, $general] as $user) {
            $this->actingAsPanelUser($user)->get(route('system.extensions.index'))->assertForbidden();
            $this->actingAsPanelUser($user)->postJson(route('system.extensions.review'), ['manifest' => $this->encoded($this->manifest())])->assertForbidden();
            $this->install($user, $this->manifest())->assertForbidden();
        }
        $this->assertSame(0, Extension::query()->count());

        $this->actingAsPanelUser($this->admin())->get(route('system.extensions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('System/Extensions/Index')->has('extensions', 0));
    }

    public function test_install_makes_a_service_account_and_a_token_with_only_the_approved_scopes(): void
    {
        $admin = $this->admin();

        $response = $this->install($admin, $this->manifest(), ['websites:read'])->assertCreated();

        $plain = $response->json('credentials.token');
        $secret = $response->json('credentials.secret');
        $this->assertStringStartsWith(ApiToken::PREFIX, $plain);
        $this->assertSame(48, strlen($secret));

        $extension = Extension::query()->sole();
        $this->assertSame(['websites:read'], $extension->grantedScopes());
        $this->assertTrue($extension->enabled);
        $this->assertSame($admin->id, $extension->installed_by);
        // The secret is stored encrypted, the token only as a hash.
        $this->assertNotSame($secret, $extension->getRawOriginal('webhook_secret'));
        $this->assertSame($secret, $extension->webhook_secret);

        $token = ApiToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(['websites:read'], $token->scopes);
        $this->assertSame($extension->service_user_id, $token->user_id);
        $this->assertNotSame($admin->id, $token->user_id);
        $service = $extension->serviceUser;
        $this->assertTrue($service->is_service_account);
        $this->assertSame('admin', $service->role);

        // Shown once: the page never carries the plain token or the secret.
        $page = $this->actingAsPanelUser($admin)->get(route('system.extensions.index'))->assertOk();
        $this->assertStringNotContainsString($plain, $page->getContent());
        $this->assertStringNotContainsString($secret, $page->getContent());
        $this->assertSame(1, ActivityLog::query()->where('action', 'extension.installed')->count());

        // dns:read was asked for but not approved.
        $this->api($plain, 'GET', '/websites')->assertOk();
        $this->api($plain, 'GET', '/dns/zones')->assertForbidden();
        $this->api($plain, 'POST', '/websites', ['domain' => 'x.example.com', 'php_version' => '8.3'])->assertForbidden();
        $this->api($plain, 'GET', '/me')->assertOk();
    }

    public function test_scopes_beyond_the_manifest_are_never_granted(): void
    {
        $admin = $this->admin();

        $this->install($admin, $this->manifest(), ['websites:read', 'websites:write'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scopes');
        $this->install($admin, $this->manifest(), ['mail:write'])->assertUnprocessable();
        $this->install($admin, $this->manifest(), ['made:up'])->assertUnprocessable();

        $this->assertSame(0, Extension::query()->count());
        $this->assertSame(0, ApiToken::query()->count());
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('is_service_account', true)->count());
    }

    public function test_events_without_a_granted_scope_are_dropped(): void
    {
        $this->install($this->admin(), $this->manifest(), ['dns:read'])->assertCreated();

        $this->assertSame([], Extension::query()->sole()->events);
    }

    public function test_the_service_account_cannot_sign_in_and_is_hidden_from_user_lists(): void
    {
        $admin = $this->admin();
        $this->install($admin, $this->manifest())->assertCreated();
        $service = Extension::query()->sole()->serviceUser;
        $service->forceFill(['password' => Hash::make('known-password')])->save();

        $this->assertNull(User::query()->find($service->id));
        $this->assertSame(1, User::query()->count());

        // Sign out the admin the install acted as, then try the service account.
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $service->email, 'password' => 'known-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAsPanelUser($admin)->get(route('admin.panel'))->assertOk()->assertSee($admin->email)->assertDontSee($service->email);
    }

    public function test_disable_revokes_access_and_enable_restores_it(): void
    {
        $admin = $this->admin();
        $plain = $this->install($admin, $this->manifest())->json('credentials.token');
        $extension = Extension::query()->sole();

        $this->actingAsPanelUser($admin)->putJson(route('system.extensions.state', $extension), ['enabled' => false])
            ->assertOk()->assertJsonPath('extension.enabled', false);
        $this->api($plain, 'GET', '/websites')->assertForbidden()->assertJsonPath('message', 'This extension is disabled.');
        $this->actingAsPanelUser($admin)->get(route('system.extensions.page', $extension))->assertNotFound();

        $this->actingAsPanelUser($admin)->putJson(route('system.extensions.state', $extension), ['enabled' => true])->assertOk();
        $this->api($plain, 'GET', '/websites')->assertOk();
        $this->assertSame(1, ActivityLog::query()->where('action', 'extension.disabled')->count());
    }

    public function test_new_credentials_replace_the_old_token_at_once(): void
    {
        $admin = $this->admin();
        $old = $this->install($admin, $this->manifest())->json('credentials');
        $extension = Extension::query()->sole();

        $new = $this->actingAsPanelUser($admin)->postJson(route('system.extensions.credentials', $extension))->assertOk()->json('credentials');

        $this->assertNotSame($old['token'], $new['token']);
        $this->assertNotSame($old['secret'], $new['secret']);
        $this->api($old['token'], 'GET', '/websites')->assertUnauthorized();
        $this->api($new['token'], 'GET', '/websites')->assertOk();
        $this->assertSame(1, ApiToken::query()->count());
        $this->assertSame(['websites:read', 'dns:read'], ApiToken::query()->sole()->scopes);
    }

    public function test_an_update_keeps_the_token_and_takes_the_newly_approved_scopes(): void
    {
        $admin = $this->admin();
        $plain = $this->install($admin, $this->manifest(), ['websites:read'])->json('credentials.token');

        $next = $this->manifest(['version' => '1.1.0', 'scopes' => ['websites:read', 'dns:write']]);
        $this->actingAsPanelUser($admin)->postJson(route('system.extensions.review'), ['manifest' => $this->encoded($next)])
            ->assertOk()->assertJsonPath('installed.version', '1.0.0')->assertJsonPath('installed.scopes', ['websites:read']);
        $this->install($admin, $next, ['websites:read', 'dns:write'])
            ->assertOk()->assertJsonMissingPath('credentials')->assertJsonPath('extension.version', '1.1.0');

        $this->assertSame(['websites:read', 'dns:write'], ApiToken::query()->sole()->scopes);
        $this->api($plain, 'GET', '/dns/zones')->assertOk();

        // An update cannot sneak in a scope the new manifest does not ask for.
        $this->install($admin, $this->manifest(['version' => '1.2.0', 'scopes' => ['websites:read']]), ['websites:read', 'dns:write'])
            ->assertUnprocessable();
        $this->assertSame('1.1.0', Extension::query()->sole()->version);
    }

    public function test_uninstall_removes_the_token_the_service_account_and_the_record(): void
    {
        $admin = $this->admin();
        $plain = $this->install($admin, $this->manifest())->json('credentials.token');
        $extension = Extension::query()->sole();
        $serviceId = $extension->service_user_id;
        Http::fake();
        $site = $this->website('kept.example.com');

        $this->actingAsPanelUser($admin)->deleteJson(route('system.extensions.destroy', $extension))->assertOk();

        $this->assertSame(0, Extension::query()->count());
        $this->assertSame(0, ApiToken::query()->count());
        $this->assertFalse(User::query()->withoutGlobalScopes()->whereKey($serviceId)->exists());
        $this->assertTrue($admin->fresh() !== null);
        $this->assertNotNull($site->fresh());
        $this->api($plain, 'GET', '/websites')->assertUnauthorized();
        $this->assertSame(1, ActivityLog::query()->where('action', 'extension.uninstalled')->count());
    }
}
