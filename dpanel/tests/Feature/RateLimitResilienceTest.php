<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * A limiter guards the panel; it must never be the reason a request fails.
 */
class RateLimitResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'throttle:2,1'])
            ->get('/__throttle-probe', fn () => response()->json(['ok' => true]))
            ->name('throttle.probe');
    }

    /** Replace the limiter's cache with one that throws, as a refused Redis does. */
    private function breakLimiterBackend(): void
    {
        $broken = new class implements Repository
        {
            public function has($key): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('Connection refused');
            }

            public function pull($key, $default = null): mixed
            {
                throw new RuntimeException('Connection refused');
            }

            public function put($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function add($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function increment($key, $value = 1)
            {
                throw new RuntimeException('Connection refused');
            }

            public function decrement($key, $value = 1)
            {
                throw new RuntimeException('Connection refused');
            }

            public function forever($key, $value): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function remember($key, $ttl, Closure $callback): mixed
            {
                throw new RuntimeException('Connection refused');
            }

            public function sear($key, Closure $callback): mixed
            {
                throw new RuntimeException('Connection refused');
            }

            public function rememberForever($key, Closure $callback): mixed
            {
                throw new RuntimeException('Connection refused');
            }

            public function forget($key): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function getStore()
            {
                throw new RuntimeException('Connection refused');
            }

            public function missing($key): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function delete($key): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function clear(): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function getMultiple($keys, $default = null): iterable
            {
                throw new RuntimeException('Connection refused');
            }

            public function setMultiple($values, $ttl = null): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function deleteMultiple($keys): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function set($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('Connection refused');
            }
        };

        $this->app->instance(RateLimiter::class, new RateLimiter($broken));
    }

    public function test_requests_are_served_when_the_limiter_backend_is_down(): void
    {
        $this->breakLimiterBackend();

        // Well past the 2/minute limit: nothing can be counted, so nothing blocks.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/__throttle-probe')->assertOk()->assertJson(['ok' => true]);
        }
    }

    public function test_the_limit_still_applies_when_the_backend_works(): void
    {
        $this->getJson('/__throttle-probe')->assertOk();
        $this->getJson('/__throttle-probe')->assertOk();

        $this->getJson('/__throttle-probe')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_the_kill_switch_disables_every_throttle(): void
    {
        config(['dpanel.rate_limit.enabled' => false]);

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/__throttle-probe')->assertOk();
        }
    }

    public function test_a_route_with_its_own_throttle_is_left_to_that_limit(): void
    {
        // A baseline of 1 would stop the first request if it also applied
        // here; the route's own limit of 2 must be the only one counting.
        config(['dpanel.rate_limit.baseline_per_minute' => 1]);

        $this->getJson('/__throttle-probe')->assertOk();
        $this->getJson('/__throttle-probe')->assertOk();
        $this->getJson('/__throttle-probe')->assertStatus(429);
    }

    public function test_an_exempt_route_has_no_baseline_at_all(): void
    {
        config([
            'dpanel.rate_limit.baseline_per_minute' => 2,
            'dpanel.rate_limit.baseline_exempt' => ['probe.exempt'],
        ]);

        Route::middleware('web')->get('/__exempt', fn () => 'ok')->name('probe.exempt');

        for ($i = 0; $i < 5; $i++) {
            $this->get('/__exempt')->assertOk();
        }
    }

    public function test_the_baseline_limits_a_route_that_has_no_throttle_of_its_own(): void
    {
        config(['dpanel.rate_limit.baseline_per_minute' => 3]);

        Route::middleware('web')
            ->get('/__baseline-probe', fn () => response()->json(['ok' => true]))
            ->name('baseline.probe');

        $this->getJson('/__baseline-probe')->assertOk();
        $this->getJson('/__baseline-probe')->assertOk();
        $this->getJson('/__baseline-probe')->assertOk();
        $this->getJson('/__baseline-probe')->assertStatus(429);
    }

    public function test_each_route_counts_in_its_own_bucket(): void
    {
        config(['dpanel.rate_limit.baseline_per_minute' => 2]);

        Route::middleware('web')->get('/__a', fn () => 'a')->name('probe.a');
        Route::middleware('web')->get('/__b', fn () => 'b')->name('probe.b');

        $this->get('/__a')->assertOk();
        $this->get('/__a')->assertOk();
        $this->get('/__a')->assertStatus(429);

        // A different route keeps its own allowance.
        $this->get('/__b')->assertOk();
    }
}
