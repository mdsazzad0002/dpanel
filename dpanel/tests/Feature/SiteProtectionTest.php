<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGuardSetting;
use App\Models\WebsiteProtectedDirectory;
use App\Services\EdgeGatewayReloader;
use App\Support\BasicAuthHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteProtectionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var list<array<int, string>> */
    private array $reloaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        $this->mock(EdgeGatewayReloader::class)->shouldReceive('reloadDomains')
            ->andReturnUsing(function (array $domains) {
                $this->reloaded[] = $domains;

                return true;
            });
    }

    private function website(User $owner, string $domain = 'shop.test', ?string $parent = null): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => $parent ? 'alias' : 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id, 'parent_id' => $parent,
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

    public function test_adding_a_user_stores_a_hash_the_gateway_can_check_and_reloads_the_aliases_too(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);
        $this->website($admin, 'brand.test', $site->id);

        $this->as($admin)->post("/cpsess{$this->token}/rules/site-protection/{$site->id}/users", [
            'path' => '/Admin/', 'realm' => 'Staff', 'username' => 'alice', 'password' => 'correct horse',
        ])->assertSessionHasNoErrors();

        $row = WebsiteProtectedDirectory::query()->sole();
        $this->assertSame('/admin', $row->path);
        $this->assertStringStartsWith('s256i$', $row->password_hash);
        $this->assertTrue(BasicAuthHash::check('correct horse', $row->password_hash));
        $this->assertEqualsCanonicalizing(['brand.test', 'shop.test'], end($this->reloaded));
    }

    public function test_adding_the_same_user_again_changes_the_password(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);
        foreach (['first password', 'second password'] as $password) {
            $this->as($admin)->post("/cpsess{$this->token}/rules/site-protection/{$site->id}/users", [
                'path' => '/admin', 'username' => 'alice', 'password' => $password,
            ])->assertSessionHasNoErrors();
        }

        $this->assertTrue(BasicAuthHash::check('second password', WebsiteProtectedDirectory::query()->sole()->password_hash));
    }

    public function test_a_path_with_a_comma_or_a_quote_in_the_realm_is_refused(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);

        $this->as($admin)->post("/cpsess{$this->token}/rules/site-protection/{$site->id}/users", [
            'path' => '/a,b', 'realm' => 'x"y', 'username' => 'alice', 'password' => 'long enough',
        ])->assertSessionHasErrors(['path', 'realm']);
    }

    public function test_settings_are_saved_as_the_lists_the_gateway_reads(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);

        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/settings", [
            'no_php_paths' => ['/wp-content/uploads/', '/files'],
            'hotlink_protection' => true,
            'hotlink_allowed_hosts' => ['CDN.example.com'],
            'cloudflare_only' => true,
        ])->assertSessionHasNoErrors();

        $setting = WebsiteGuardSetting::query()->findOrFail($site->id);
        $this->assertSame('/wp-content/uploads,/files', $setting->no_php_paths);
        $this->assertSame('cdn.example.com', $setting->hotlink_allowed_hosts);
        $this->assertTrue($setting->hotlink_protection);
        $this->assertTrue($setting->cloudflare_only);
    }

    public function test_php_cannot_be_turned_off_for_the_whole_site(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);

        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/settings", [
            'no_php_paths' => ['/'], 'hotlink_protection' => false, 'hotlink_allowed_hosts' => [],
        ])->assertSessionHasErrors('no_php_paths.0');
    }

    public function test_someone_elses_website_is_not_found(): void
    {
        $owner = User::factory()->create();
        $site = $this->website($owner);
        $other = User::factory()->create();
        $other->assignRole('reseller');

        $this->as($other)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/settings", [
            'no_php_paths' => [], 'hotlink_protection' => true, 'hotlink_allowed_hosts' => [],
        ])->assertNotFound();
    }

    public function test_the_page_lists_folders_with_their_users_but_never_a_hash(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);
        WebsiteProtectedDirectory::query()->create(['website_id' => $site->id, 'path' => '/admin', 'username' => 'alice', 'password_hash' => 's256i$1$00$00']);

        $this->as($admin)->get("/cpsess{$this->token}/rules/site-protection")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Rules/SiteProtection')
                ->where('websites.0.directories.0.path', '/admin')
                ->where('websites.0.directories.0.users.0.username', 'alice'))
            ->assertDontSee('s256i');
    }

    public function test_the_firewall_defaults_to_detect_and_its_mode_can_be_set(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);
        $this->website($admin, 'brand.test', $site->id);

        $this->as($admin)->get("/cpsess{$this->token}/rules/site-protection")
            ->assertInertia(fn ($page) => $page->where('websites.0.waf_mode', 'detect')->where('wafAvailable', false));

        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/waf", ['waf_mode' => 'block'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('block', WebsiteGuardSetting::query()->find($site->id)->waf_mode);
        $this->assertEqualsCanonicalizing(['shop.test', 'brand.test'], end($this->reloaded));

        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/waf", ['waf_mode' => 'panic'])
            ->assertSessionHasErrors('waf_mode');
    }

    public function test_saving_the_other_settings_keeps_the_firewall_mode(): void
    {
        $admin = $this->admin();
        $site = $this->website($admin);
        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/waf", ['waf_mode' => 'off']);

        $this->as($admin)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/settings", [
            'no_php_paths' => ['/uploads'], 'hotlink_protection' => false, 'hotlink_allowed_hosts' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('off', WebsiteGuardSetting::query()->find($site->id)->waf_mode);
    }

    public function test_another_customers_site_cannot_be_changed(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('general');
        $other = User::factory()->create();
        $other->assignRole('general');
        $site = $this->website($owner);

        $this->as($other)->put("/cpsess{$this->token}/rules/site-protection/{$site->id}/waf", ['waf_mode' => 'off'])->assertNotFound();
    }
}
