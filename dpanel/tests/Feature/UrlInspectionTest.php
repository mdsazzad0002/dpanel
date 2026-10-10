<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seo\UrlInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class UrlInspectionTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('c', 64);
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert([
            ['id' => 'site-1', 'domain' => 'Shop.test', 'scope' => 'user', 'enable_ssl' => true],
            ['id' => 'panel', 'domain' => 'panel.test', 'scope' => 'system', 'enable_ssl' => true],
        ]);
    }

    public function test_internal_website_path_builds_the_url(): void
    {
        $this->expectInspect('https://shop.test/blog/post?page=2', 'googlebot');

        $this->inspect(['website_id' => 'site-1', 'path' => 'blog/post?page=2', 'agent' => 'googlebot'])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_internal_root_and_external_urls(): void
    {
        $this->expectInspect('https://shop.test/', 'default');
        $this->inspect(['website_id' => 'site-1'])->assertOk();

        $this->expectInspect('http://example.org/a/b?x=1', 'default');
        $this->inspect(['source' => 'external', 'url' => 'http://Example.org/a/b?x=1'])->assertOk();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->app->instance(UrlInspector::class, Mockery::mock(UrlInspector::class)->shouldNotReceive('inspect')->getMock());

        foreach (['https://example.org:8443/', 'https://user@example.org/', 'http://10.0.0.1/', 'javascript:alert(1)'] as $url) {
            $this->inspect(['source' => 'external', 'url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->inspect(['website_id' => 'site-1', 'path' => 'https://evil.test/'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->inspect(['website_id' => 'site-1', 'agent' => 'curl'])->assertUnprocessable()->assertJsonValidationErrors('agent');
        $this->inspect(['website_id' => 'panel'])->assertNotFound();
    }

    private function expectInspect(string $url, string $agent): void
    {
        $inspector = Mockery::mock(UrlInspector::class);
        $inspector->shouldReceive('inspect')->once()->with($url, $agent)->andReturn(['ok' => true]);
        $this->app->instance(UrlInspector::class, $inspector);
    }

    private function inspect(array $body): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/seo/url-inspection", $body);
    }
}
