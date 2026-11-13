<?php

namespace Tests\Unit;

use App\Services\EdgeGatewayReloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class EdgeGatewayReloaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This test is about the real reloader, which TestCase replaces.
        $this->app->forgetInstance(EdgeGatewayReloader::class);
    }

    public function test_it_requests_an_authenticated_live_reload(): void
    {
        config(['dpanel.edge_gateway_internal_url' => 'http://127.0.0.1', 'dpanel.execution_api_token' => 'test-token']);
        Http::fake(['http://127.0.0.1/__admin/reload' => Http::response(['success' => true])]);
        $this->expectPublish()->andReturn(0);

        $this->assertTrue(app(EdgeGatewayReloader::class)->reload());
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1/__admin/reload'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_uses_redis_without_waiting_for_http_when_a_listener_is_ready(): void
    {
        $this->expectPublish()->withArgs(fn ($channel) => $channel === 'edge:reload')->andReturn(1);
        Http::fake();
        $this->assertTrue(app(EdgeGatewayReloader::class)->reload());
        Http::assertNothingSent();
    }

    public function test_it_keeps_the_previous_snapshot_when_reload_fails(): void
    {
        $this->expectPublish()->andReturn(0);
        Http::fake(['*' => Http::response(['success' => false], 503)]);
        $this->assertFalse(app(EdgeGatewayReloader::class)->reload());
    }

    private function expectPublish(): \Mockery\CompositeExpectation
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('website_cache')->andReturn($connection);

        return $connection->shouldReceive('publish')->once();
    }
}
