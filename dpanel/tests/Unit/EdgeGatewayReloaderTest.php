<?php

namespace Tests\Unit;

use App\Services\EdgeGatewayReloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EdgeGatewayReloaderTest extends TestCase
{
    public function test_it_requests_an_authenticated_live_reload(): void
    {
        config(['serverpanel.edge_gateway_internal_url' => 'http://127.0.0.1', 'serverpanel.execution_api_token' => 'test-token']);
        Http::fake(['http://127.0.0.1/__admin/reload' => Http::response(['success' => true])]);
        Redis::shouldReceive('publish')->once()->andReturn(0);

        $this->assertTrue(app(EdgeGatewayReloader::class)->reload());
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1/__admin/reload'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_uses_redis_without_waiting_for_http_when_a_listener_is_ready(): void
    {
        Redis::shouldReceive('publish')->once()->withArgs(fn ($channel) => $channel === 'edge:reload')->andReturn(1);
        Http::fake();
        $this->assertTrue(app(EdgeGatewayReloader::class)->reload());
        Http::assertNothingSent();
    }

    public function test_it_keeps_the_previous_snapshot_when_reload_fails(): void
    {
        Redis::shouldReceive('publish')->once()->andReturn(0);
        Http::fake(['*' => Http::response(['success' => false], 503)]);
        $this->assertFalse(app(EdgeGatewayReloader::class)->reload());
    }
}
