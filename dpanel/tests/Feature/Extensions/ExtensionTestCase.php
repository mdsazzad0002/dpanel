<?php

namespace Tests\Feature\Extensions;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Shared set-up for the extension tests (backlog U7). */
abstract class ExtensionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.version' => '1.4.0', 'app.url' => 'https://panel.example.com']);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array<string, mixed> */
    protected function manifest(array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => 'acme.uptime',
            'name' => 'Uptime report',
            'version' => '1.0.0',
            'description' => 'Watches every website.',
            'author' => 'Acme',
            'panel' => '>=1.0 <2.0',
            'scopes' => ['websites:read', 'dns:read'],
            'ui' => ['admin_page' => ['title' => 'Uptime', 'url' => 'https://uptime.example.net/dpanel']],
            'webhook' => ['url' => 'https://uptime.example.net/dpanel/events'],
            'events' => ['website.created', 'website.deleted'],
        ], $overrides);
    }

    protected function encoded(array $manifest): string
    {
        return (string) json_encode($manifest);
    }

    /** Installs as `$admin` and returns the response. */
    protected function install(User $admin, array $manifest, ?array $scopes = null): TestResponse
    {
        return $this->actingAsPanelUser($admin)->postJson(route('system.extensions.store'), [
            'manifest' => $this->encoded($manifest),
            'scopes' => $scopes ?? $manifest['scopes'],
        ]);
    }

    protected function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withToken($token)->json($method, '/api/v1'.$uri, $data);
    }

    protected function website(string $domain): Website
    {
        $owner = str_replace(['.', '-'], '_', $domain);

        return Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary', 'ssl_mode' => 'none',
        ]);
    }
}
