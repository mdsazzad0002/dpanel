<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteRedirectRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class RedirectRuleTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('a', 64);
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
            $table->string('scope')->nullable();
            $table->boolean('enable_ssl')->default(false);
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_10_000000_create_website_redirect_rules_table.php'))->up();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert([
            ['id' => 'site-1', 'domain' => 'shop.test', 'scope' => null, 'enable_ssl' => true],
            ['id' => 'site-2', 'domain' => 'blog.test', 'scope' => 'user', 'enable_ssl' => false],
            ['id' => '1', 'domain' => 'panel.test', 'scope' => 'system', 'enable_ssl' => true],
        ]);
    }

    public function test_rule_is_created_and_applied_to_its_domain(): void
    {
        $this->expectPublish()
            ->withArgs(fn ($channel, $payload) => json_decode($payload, true)['domains'] === ['shop.test'])
            ->andReturn(1);

        $this->request()->post("/cpsess{$this->token}/rules/redirects", [
            'website_id' => 'site-1',
            'kind' => 'to_domain',
            'name' => 'Moved',
            'target' => 'New.Test.',
            'status_code' => 302,
            'preserve_query' => false,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $rule = WebsiteRedirectRule::query()->sole();
        $this->assertSame('new.test', $rule->target);
        $this->assertSame(302, $rule->status_code);
        $this->assertFalse($rule->preserve_query);
    }

    public function test_domain_target_must_be_a_bare_hostname(): void
    {
        $this->request()->post("/cpsess{$this->token}/rules/redirects", [
            'website_id' => 'site-1',
            'kind' => 'to_domain',
            'name' => 'Moved',
            'target' => 'https://new.test/x',
            'status_code' => 302,
            'preserve_query' => false,
        ])->assertSessionHasErrors('target');
        $this->assertSame(0, WebsiteRedirectRule::query()->count());
    }

    public function test_https_rule_needs_ssl_and_kinds_are_unique_per_site(): void
    {
        $payload = ['kind' => 'http_to_https', 'name' => 'HTTPS', 'status_code' => 301, 'preserve_query' => true];

        $this->request()->post("/cpsess{$this->token}/rules/redirects", ['website_id' => 'site-2', ...$payload])
            ->assertSessionHasErrors('website_id');

        WebsiteRedirectRule::query()->create(['website_id' => 'site-1', ...$payload]);
        $this->request()->post("/cpsess{$this->token}/rules/redirects", ['website_id' => 'site-1', ...$payload])
            ->assertSessionHasErrors('kind');
    }

    public function test_panel_site_is_out_of_reach(): void
    {
        $this->request()->post("/cpsess{$this->token}/rules/redirects", [
            'website_id' => '1',
            'kind' => 'www_to_root',
            'name' => 'WWW',
            'status_code' => 301,
            'preserve_query' => true,
        ])->assertNotFound();
    }

    public function test_toggle_and_delete_reload_the_domain(): void
    {
        $rule = WebsiteRedirectRule::query()->create([
            'website_id' => 'site-1', 'kind' => 'www_to_root', 'name' => 'WWW', 'status_code' => 301, 'preserve_query' => true, 'enabled' => true,
        ]);
        $this->expectPublish(2)->andReturn(1);

        $this->request()->patch("/cpsess{$this->token}/rules/redirects/{$rule->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($rule->fresh()->enabled);

        $this->request()->delete("/cpsess{$this->token}/rules/redirects/{$rule->id}")->assertSessionHas('success');
        $this->assertSame(0, WebsiteRedirectRule::query()->count());
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }

    private function expectPublish(int $times = 1): mixed
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->times($times)->with('website_cache')->andReturn($connection);

        return $connection->shouldReceive('publish')->times($times);
    }
}
