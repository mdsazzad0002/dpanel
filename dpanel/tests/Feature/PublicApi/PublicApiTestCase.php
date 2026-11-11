<?php

namespace Tests\Feature\PublicApi;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Shared set-up for the public API tests (backlog U1). */
abstract class PublicApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role = 'general', array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user->refresh();
    }

    /** @param list<string> $scopes */
    protected function token(User $user, array $scopes, array $attributes = []): string
    {
        $generated = ApiToken::generate();
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scopes' => $scopes,
        ] + $attributes);

        return $generated['plain'];
    }

    protected function api(string $plain, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withToken($plain)->json($method, '/api/v1'.$uri, $data);
    }

    protected function website(?User $owner, string $domain, array $attributes = []): Website
    {
        $owner_name = str_replace(['.', '-'], '_', $domain);

        return Website::query()->create($attributes + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner_name}", 'root_path' => "/home/{$owner_name}/public_html", 'site_owner' => $owner_name,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner?->id, 'assigned_reseller_id' => $owner?->reseller_id,
        ]);
    }
}
