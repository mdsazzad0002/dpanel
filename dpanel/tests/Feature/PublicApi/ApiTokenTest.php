<?php

namespace Tests\Feature\PublicApi;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Role;
use App\Services\AccountSuspensionService;
use App\Support\RolePermissions;

/** Backlog U1: tokens are made in the panel, hashed, scoped, and never outdo their owner. */
class ApiTokenTest extends PublicApiTestCase
{
    public function test_a_user_makes_a_token_that_is_shown_once_and_stored_hashed(): void
    {
        $user = $this->user();

        $response = $this->actingAsPanelUser($user)->postJson(route('settings.api-tokens.store'), [
            'name' => 'Deploy script',
            'scopes' => ['dns:write', 'websites:read'],
            'expires_in_days' => 30,
        ])->assertCreated();

        $plain = $response->json('plain_token');
        $this->assertStringStartsWith(ApiToken::PREFIX, $plain);
        $token = ApiToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertNotSame($plain, $token->token_hash);
        $this->assertSame(['websites:read', 'dns:write'], $token->scopes);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $page = $this->actingAsPanelUser($user)->get(route('settings.api-tokens'))->assertOk();
        $page->assertInertia(fn ($inertia) => $inertia->component('Settings/ApiTokens/Index')->has('tokens', 1));
        $this->assertStringNotContainsString($plain, $page->getContent());
        $this->assertSame(1, ActivityLog::query()->where('action', 'api.token_created')->count());
    }

    public function test_a_token_cannot_get_a_scope_its_owner_lacks(): void
    {
        RolePermissions::ensureRole('general');
        Role::query()->where('name', 'general')->update(['permissions_csv' => 'view_dashboard,manage_dns']);
        $user = $this->user();

        $this->actingAsPanelUser($user)->get(route('settings.api-tokens'))
            ->assertInertia(fn ($inertia) => $inertia->where('scopes', ['dns:read', 'dns:write']));
        $this->actingAsPanelUser($user)->postJson(route('settings.api-tokens.store'), [
            'name' => 'Too much', 'scopes' => ['dns:read', 'websites:write'],
        ])->assertUnprocessable()->assertJsonValidationErrors('scopes.1');
        $this->actingAsPanelUser($user)->postJson(route('settings.api-tokens.store'), [
            'name' => 'Made up', 'scopes' => ['servers:root'],
        ])->assertUnprocessable();
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_only_the_owner_sees_and_revokes_a_token(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $plain = $this->token($owner, ['websites:read']);
        $token = ApiToken::query()->sole();

        $this->actingAsPanelUser($other)->get(route('settings.api-tokens'))
            ->assertInertia(fn ($inertia) => $inertia->has('tokens', 0));
        $this->actingAsPanelUser($other)->deleteJson(route('settings.api-tokens.destroy', ['apiToken' => $token->id]))->assertNotFound();
        $this->api($plain, 'GET', '/websites')->assertOk();

        $this->actingAsPanelUser($owner)->deleteJson(route('settings.api-tokens.destroy', ['apiToken' => $token->id]))->assertOk();
        $this->assertSame(0, ApiToken::query()->count());
        $this->api($plain, 'GET', '/websites')->assertUnauthorized();
    }

    public function test_requests_without_a_usable_token_get_a_json_401(): void
    {
        $user = $this->user();
        $expired = $this->token($user, ['websites:read'], ['expires_at' => now()->subMinute()]);

        // No Accept header: the API answers in JSON anyway.
        $this->get('/api/v1/websites')->assertUnauthorized()->assertJsonStructure(['message'])->assertHeader('WWW-Authenticate');
        $this->withToken('not-a-dpanel-token')->get('/api/v1/websites')->assertUnauthorized();
        $this->api(ApiToken::PREFIX.str_repeat('x', 48), 'GET', '/websites')->assertUnauthorized();
        $this->api($expired, 'GET', '/websites')->assertUnauthorized()->assertJsonPath('message', 'Invalid, expired or revoked API token.');
    }

    public function test_a_suspended_account_is_refused_until_it_is_unsuspended(): void
    {
        $user = $this->user();
        $plain = $this->token($user, ['websites:read']);
        $suspension = app(AccountSuspensionService::class);

        $suspension->suspend($user);
        $this->api($plain, 'GET', '/websites')->assertForbidden()->assertJsonPath('message', 'The account that owns this API token is suspended.');

        $suspension->unsuspend($user->refresh());
        $this->api($plain, 'GET', '/websites')->assertOk();
    }

    public function test_a_customer_of_a_suspended_reseller_is_refused(): void
    {
        $reseller = $this->user('reseller');
        $customer = $this->user('general', ['reseller_id' => $reseller->id]);
        $plain = $this->token($customer, ['websites:read']);

        app(AccountSuspensionService::class)->suspend($reseller);

        $this->api($plain, 'GET', '/websites')->assertForbidden();
    }

    public function test_each_endpoint_needs_its_scope_and_write_also_reads(): void
    {
        $user = $this->user();
        $dnsOnly = $this->token($user, ['dns:read']);
        $mailWriter = $this->token($user, ['mail:write']);

        $this->api($dnsOnly, 'GET', '/dns/zones')->assertOk();
        $this->api($dnsOnly, 'GET', '/websites')->assertForbidden()->assertJsonPath('message', 'This token does not have the websites:read scope.');
        $this->api($dnsOnly, 'POST', '/dns/zones/example.com/records', [])->assertForbidden();
        $this->api($mailWriter, 'GET', '/mailboxes')->assertOk();
        $this->api($mailWriter, 'GET', '/databases')->assertForbidden();
    }

    public function test_a_scope_stops_working_when_the_owner_loses_the_permission(): void
    {
        $user = $this->user();
        $plain = $this->token($user, ['websites:read', 'dns:read']);
        $this->api($plain, 'GET', '/websites')->assertOk();

        Role::query()->where('name', 'general')->update(['permissions_csv' => 'view_dashboard,manage_dns']);

        $this->api($plain, 'GET', '/websites')->assertForbidden();
        $this->api($plain, 'GET', '/dns/zones')->assertOk();
        $this->api($plain, 'GET', '/me')->assertOk()->assertJsonPath('data.token.usable_scopes', ['dns:read']);
    }

    public function test_use_is_recorded_and_changes_are_in_the_activity_log(): void
    {
        $user = $this->user();
        $plain = $this->token($user, ['dns:write']);

        $this->api($plain, 'GET', '/me')->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token.scopes', ['dns:write']);
        $token = ApiToken::query()->sole();
        $this->assertNotNull($token->last_used_at);
        $this->assertSame('127.0.0.1', $token->last_used_ip);
        $this->assertSame(0, ActivityLog::query()->where('action', 'api.request')->count());

        $this->api($plain, 'POST', '/dns/zones/nowhere.test/records', ['type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 300])->assertNotFound();
        // Only changes that went through are logged.
        $this->assertSame(0, ActivityLog::query()->where('action', 'api.request')->count());
    }

    public function test_each_token_is_rate_limited(): void
    {
        config(['api.rate_limit' => 2]);
        $user = $this->user();
        $first = $this->token($user, ['websites:read']);
        $second = $this->token($user, ['websites:read']);

        $this->api($first, 'GET', '/websites')->assertOk();
        $this->api($first, 'GET', '/me')->assertOk();
        $this->api($first, 'GET', '/websites')->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['message']);
        $this->api($second, 'GET', '/websites')->assertOk();
    }
}
