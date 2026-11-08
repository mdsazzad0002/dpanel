<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteLogTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
    }

    private function website(User $owner, string $siteOwner = 'shop', string $domain = 'shop.test'): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => $siteOwner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_it_reads_the_access_log_by_the_sites_domain(): void
    {
        Http::fake(['drust.test/sites/logs*' => Http::response(['success' => true, 'exists' => true, 'lines' => ['a', 'b']])]);
        $site = $this->website($this->admin());

        $this->as(User::query()->first())->getJson("/cpsess{$this->token}/websites/{$site->id}/logs/lines?kind=access&lines=50")
            ->assertOk()->assertJsonPath('lines', ['a', 'b']);

        Http::assertSent(fn ($request) => $request['kind'] === 'access' && $request['domain'] === 'shop.test' && (int) $request['lines'] === 50);
    }

    public function test_the_php_log_goes_by_the_sites_account_and_a_strange_one_reads_nothing(): void
    {
        Http::fake(['drust.test/sites/logs*' => Http::response(['success' => true, 'exists' => true, 'lines' => ['PHP Warning: x']])]);
        $admin = $this->admin();
        $site = $this->website($admin);
        $odd = $this->website($admin, '../root', 'odd.test');

        $this->as($admin)->getJson("/cpsess{$this->token}/websites/{$site->id}/logs/lines?kind=php")->assertOk();
        $this->as($admin)->getJson("/cpsess{$this->token}/websites/{$odd->id}/logs/lines?kind=php")
            ->assertOk()->assertJsonPath('exists', false);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['owner'] === 'shop');
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        Http::fake();
        $admin = $this->admin();
        $site = $this->website($admin);

        $this->as($admin)->getJson("/cpsess{$this->token}/websites/{$site->id}/logs/lines?kind=shadow")->assertJsonValidationErrors('kind');
        Http::assertNothingSent();
    }

    public function test_someone_elses_site_is_not_found(): void
    {
        Http::fake();
        $site = $this->website($this->admin());
        $other = User::factory()->create();
        $other->assignRole('user');

        $this->as($other)->getJson("/cpsess{$this->token}/websites/{$site->id}/logs/lines?kind=access")->assertNotFound();
        Http::assertNothingSent();
    }
}
