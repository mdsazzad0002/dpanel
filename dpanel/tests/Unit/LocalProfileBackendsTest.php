<?php

namespace Tests\Unit;

use App\Services\EdgeGatewayReloader;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LocalProfileBackendsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$server, $env]) {
            $this->restore($_SERVER, $key, $server);
            $this->restore($_ENV, $key, $env);
        }

        parent::tearDown();
    }

    private function restore(array &$target, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($target[$key]);
        } else {
            $target[$key] = $value;
        }
    }

    private function env(string $key, string $value): void
    {
        $this->savedEnv[$key] ??= [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    /** @return array<string, mixed> */
    private function configFile(string $name): array
    {
        return require config_path($name.'.php');
    }

    public function test_the_local_profile_moves_redis_to_the_database(): void
    {
        $this->env('DPANEL_PROFILE', 'local');
        $this->env('QUEUE_CONNECTION', 'redis');
        $this->env('CACHE_STORE', 'redis');

        $this->assertSame('database', $this->configFile('queue')['default']);
        $this->assertSame('database', $this->configFile('cache')['default']);
    }

    public function test_the_local_profile_keeps_any_other_choice(): void
    {
        $this->env('DPANEL_PROFILE', 'local');
        $this->env('QUEUE_CONNECTION', 'sync');
        $this->env('CACHE_STORE', 'file');

        $this->assertSame('sync', $this->configFile('queue')['default']);
        $this->assertSame('file', $this->configFile('cache')['default']);
    }

    public function test_the_server_profile_keeps_redis(): void
    {
        $this->env('DPANEL_PROFILE', 'server');
        $this->env('QUEUE_CONNECTION', 'redis');
        $this->env('CACHE_STORE', 'redis');

        $this->assertSame('redis', $this->configFile('queue')['default']);
        $this->assertSame('redis', $this->configFile('cache')['default']);
        $this->assertSame(90, $this->configFile('queue')['connections']['database']['retry_after']);
    }

    public function test_a_local_database_queue_outlives_a_long_job(): void
    {
        $this->env('DPANEL_PROFILE', 'local');

        $this->assertGreaterThan(3600, $this->configFile('queue')['connections']['database']['retry_after']);
    }

    public function test_cache_and_queue_work_on_the_database_without_redis(): void
    {
        Redis::shouldReceive('connection')->never();
        config(['cache.default' => 'database', 'queue.default' => 'database']);

        Cache::put('local-profile', 'kept', 60);
        $lock = Cache::lock('local-profile-lock', 5);
        $this->assertTrue($lock->get());
        $lock->release();
        LocalProfileProbeJob::dispatch();

        $this->assertSame('kept', Cache::get('local-profile'));
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%local-profile')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_the_gateway_reload_skips_redis_when_the_system_has_none(): void
    {
        $this->app->forgetInstance(EdgeGatewayReloader::class);
        $this->app->instance(PlatformCapabilities::class, new class extends PlatformCapabilities
        {
            public function allows(string $capability): bool
            {
                return $capability !== 'redis';
            }
        });
        config(['serverpanel.edge_gateway_internal_url' => 'http://127.0.0.1', 'serverpanel.execution_api_token' => 'test-token']);
        Http::fake(['http://127.0.0.1/__admin/reload' => Http::response(['success' => true])]);
        Redis::shouldReceive('connection')->never();

        $this->assertTrue(app(EdgeGatewayReloader::class)->reload());
        Http::assertSentCount(1);
    }
}

class LocalProfileProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}
