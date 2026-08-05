<?php

namespace Tests\Unit;

use App\Http\Controllers\RedisCacheController;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class RedisCacheControllerTest extends TestCase
{
    public function test_it_scans_the_cache_connection_without_using_keys(): void
    {
        config(['app.website_redis_connection' => 'website_cache']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('ping')->once()->andReturn(true);
        $connection->shouldReceive('scan')->once()->with(0, [
            'match' => 'sp_example_*',
            'count' => 500,
        ])->andReturn(['19', ['sp_example_one', 'sp_example_two']]);
        $connection->shouldReceive('scan')->once()->with('19', [
            'match' => 'sp_example_*',
            'count' => 500,
        ])->andReturn(['0', ['sp_example_three']]);
        $connection->shouldNotReceive('keys');
        Redis::shouldReceive('connection')->once()->with('website_cache')->andReturn($connection);

        $result = $this->invokeScan('sp_example_*', 2);

        $this->assertTrue($result['connected']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(['sp_example_one', 'sp_example_two'], $result['keys']);
    }

    public function test_it_reports_an_unavailable_redis_connection(): void
    {
        config(['app.website_redis_connection' => 'website_cache']);
        Redis::shouldReceive('connection')->once()->with('website_cache')
            ->andThrow(new RuntimeException('connection refused'));

        $result = $this->invokeScan('sp_example_*', 25);

        $this->assertFalse($result['connected']);
        $this->assertSame('Unable to connect to Redis.', $result['error']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['keys']);
    }

    /** @return array{connected:bool,error:?string,count:int,keys:array<int,string>} */
    private function invokeScan(string $pattern, ?int $limit): array
    {
        $method = new ReflectionMethod(RedisCacheController::class, 'scanRedisKeys');

        return $method->invoke(new RedisCacheController, $pattern, $limit);
    }
}
