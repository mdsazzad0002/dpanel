<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteEdgeCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebsiteEdgeCacheTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('e', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->string('scope')->default('user');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_02_000000_create_website_edge_cache_table.php'))->up();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert([
            ['id' => 'site-1', 'domain' => 'shop.test', 'scope' => 'user', 'assigned_reseller_id' => null],
            ['id' => '1', 'domain' => 'panel.test', 'scope' => 'system', 'assigned_reseller_id' => null],
        ]);
        config([
            'serverpanel.edge_gateway_internal_url' => 'http://gateway.test',
            'serverpanel.execution_api_token' => 'secret',
        ]);
    }

    public function test_settings_are_saved_cleaned_and_applied_to_the_gateway(): void
    {
        Redis::shouldReceive('publish')->once()
            ->withArgs(fn ($channel, $payload) => $channel === 'edge:reload' && json_decode($payload, true)['domains'] === ['shop.test'])
            ->andReturn(1);

        $this->request()->putJson("/cpsess{$this->token}/websites/site-1/edge-cache", [
            'mode' => 'everything',
            'edge_ttl' => 7200,
            'browser_ttl' => 0,
            'bypass_paths' => "/wp-admin\r\n\n/cart\n/cart",
            'bypass_cookies' => 'wordpress_logged_in_',
            'ignore_query_string' => true,
            'serve_stale' => false,
        ])->assertOk()->assertJsonPath('settings.mode', 'everything');

        $saved = WebsiteEdgeCache::query()->where('website_id', 'site-1')->sole();
        $this->assertSame("/wp-admin\n/cart", $saved->bypass_paths);
        $this->assertNull($saved->browser_ttl);
        $this->assertSame(7200, $saved->edge_ttl);
        $this->assertTrue($saved->ignore_query_string);
    }

    public function test_bypass_paths_must_be_paths(): void
    {
        Redis::shouldReceive('publish')->never();

        $this->request()->putJson("/cpsess{$this->token}/websites/site-1/edge-cache", [
            'mode' => 'standard', 'edge_ttl' => 3600, 'bypass_paths' => 'wp-admin', 'bypass_cookies' => '',
            'ignore_query_string' => false, 'serve_stale' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('bypass_paths');
        $this->assertSame(0, WebsiteEdgeCache::query()->count());
    }

    public function test_the_panel_site_cannot_be_cached(): void
    {
        $this->request()->putJson("/cpsess{$this->token}/websites/1/edge-cache", [
            'mode' => 'everything', 'edge_ttl' => 3600, 'ignore_query_string' => false, 'serve_stale' => true,
        ])->assertStatus(422);
    }

    public function test_purge_sends_the_site_domain_and_targets_to_the_gateway(): void
    {
        Http::fake(['gateway.test/__admin/cache/purge' => Http::response(['success' => true, 'purged' => 2])]);

        $this->request()->postJson("/cpsess{$this->token}/websites/site-1/edge-cache/purge", [
            'type' => 'prefixes',
            'targets' => "/blog/\n\n/news/",
        ])->assertOk()->assertJsonPath('purged', 2);

        Http::assertSent(fn ($request) => $request['domain'] === 'shop.test'
            && $request['prefixes'] === ['/blog/', '/news/']
            && $request['urls'] === []
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_development_mode_lasts_three_hours(): void
    {
        Redis::shouldReceive('publish')->once()->andReturn(1);

        $this->request()->postJson("/cpsess{$this->token}/websites/site-1/edge-cache/development-mode", ['enabled' => true])->assertOk();

        $until = (int) WebsiteEdgeCache::query()->where('website_id', 'site-1')->value('development_mode_until');
        $this->assertEqualsWithDelta(time() + 3 * 3600, $until, 5);
    }

    public function test_resellers_cannot_open_other_resellers_sites(): void
    {
        $reseller = User::factory()->create();
        $reseller->assignRole('reseller');

        $this->withoutMiddleware()->actingAs($reseller)->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/websites/site-1/edge-cache/purge", ['type' => 'everything'])
            ->assertNotFound();
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
