<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiGatewayProvider;
use App\Models\User;
use App\Services\AiGateway\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** No default model: the router runs the best working model in the dragged order (provider, then model). */
class AiGatewayPriorityRoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    private function provider(string $slug, int $priority): AiGatewayProvider
    {
        return AiGatewayProvider::create(['name' => $slug, 'driver' => 'groq', 'slug' => $slug, 'is_active' => true, 'weight' => $priority]);
    }

    public function test_higher_priority_provider_and_model_run_first(): void
    {
        $low = $this->provider('low', 10);
        $high = $this->provider('high', 50);
        $low->models()->create(['name' => 'low-a', 'is_active' => true, 'priority' => 999]);
        $high->models()->create(['name' => 'high-a', 'is_active' => true, 'priority' => 1]);
        $high->models()->create(['name' => 'high-b', 'is_active' => true, 'priority' => 5]);

        $order = app(Router::class)->candidates()->pluck('name')->all();

        $this->assertSame(['high-b', 'high-a', 'low-a'], $order);
    }

    public function test_a_failing_model_drops_behind_its_equals(): void
    {
        $p = $this->provider('p', 100);
        $p->models()->create(['name' => 'flaky', 'is_active' => true, 'priority' => 5, 'failure_count' => 1]);
        $p->models()->create(['name' => 'steady', 'is_active' => true, 'priority' => 5]);

        foreach (range(1, 3) as $i) {
            $this->assertSame(['steady', 'flaky'], app(Router::class)->candidates()->pluck('name')->all());
        }
    }

    public function test_unpinned_request_uses_the_top_priority_model(): void
    {
        $p = $this->provider('p', 100);
        $p->models()->create(['name' => 'older', 'is_active' => true, 'priority' => 0]);
        $p->models()->create(['name' => 'best', 'is_active' => true, 'priority' => 7]);

        $this->assertSame('best', app(Router::class)->resolve()['modelName']);
    }

    public function test_a_provider_is_created_without_a_name_and_named_after_its_driver(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([1, 2] as $i) {
            $this->actingAs($admin)->post(route('ai-gateway.providers.store', ['token' => $this->session]), [
                'driver' => 'groq', 'api_key' => 'k',
            ])->assertRedirect();
        }

        $this->assertSame(['Groq Provider', 'Groq Provider 2'], AiGatewayProvider::orderBy('id')->pluck('name')->all());
        // Each new provider goes to the bottom of the order.
        $this->assertSame([2, 1], AiGatewayProvider::orderBy('id')->pluck('weight')->all());
    }

    public function test_drag_order_sets_who_runs_first(): void
    {
        $a = $this->provider('a', 100);
        $b = $this->provider('b', 100);
        $a1 = $a->models()->create(['name' => 'a1', 'is_active' => true]);
        $b1 = $b->models()->create(['name' => 'b1', 'is_active' => true]);
        $b2 = $b->models()->create(['name' => 'b2', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('ai-gateway.providers.reorder', ['token' => $this->session]), ['ids' => [$b->id, $a->id]])->assertOk();
        $this->actingAs($admin)->postJson(route('ai-gateway.providers.models.reorder', ['token' => $this->session, 'provider' => $b->id]), ['ids' => [$b2->id, $b1->id]])->assertOk();

        $this->assertSame(['b2', 'b1', 'a1'], app(Router::class)->candidates()->pluck('name')->all());

        // The page lists them in the same order.
        $this->actingAs($admin)->get(route('ai-gateway.providers.index', ['token' => $this->session]))
            ->assertInertia(fn ($page) => $page->where('providers.0.id', $b->id)->where('providers.0.models.0.name', 'b2'));

        // A new model joins at the bottom of its provider.
        $this->actingAs($admin)->post(route('ai-gateway.models.store', ['token' => $this->session]), ['provider_id' => $b->id, 'name' => 'b3'])->assertRedirect();
        $this->assertSame(['b2', 'b1', 'b3'], $b->models()->orderByDesc('priority')->pluck('name')->all());
    }
}
