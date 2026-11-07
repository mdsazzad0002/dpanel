<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RemoteMysqlAccessRule;
use App\Models\User;
use App\Services\RemoteMysqlAccessService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Laravel passes route values by position, and `{token}` comes first on every
 * panel route. An action that leaves `$token` out gets the token as its `$id`,
 * or a TypeError when it wants a model.
 */
class PanelRouteParametersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_panel_action_takes_the_token_first(): void
    {
        $wrong = [];
        $checked = 0;

        foreach (Router::getRoutes() as $route) {
            $action = $this->reflectAction($route);
            if ($action === null || ! in_array('token', $route->parameterNames(), true)) {
                continue;
            }

            $checked++;
            $positional = $this->positionalParameters($action);
            if ($positional !== [] && $positional[0] !== 'token') {
                $wrong[] = $route->uri().' -> ('.implode(', ', $positional).')';
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $wrong);
    }

    public function test_a_notification_opens_and_is_marked_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $opened = $this->notification();
        $marked = $this->notification();

        $this->actingAsPanelUser($user)
            ->get(route('notifications.show', ['id' => $opened->id]))
            ->assertOk();
        $this->postJson(route('notifications.read', ['id' => $marked->id]))->assertOk();

        $this->assertNotNull($opened->fresh()->read_at);
        $this->assertNotNull($marked->fresh()->read_at);
    }

    public function test_a_remote_mysql_rule_can_be_removed(): void
    {
        $service = $this->mock(RemoteMysqlAccessService::class)->shouldIgnoreMissing();
        $service->shouldReceive('removeFirewallRule')->once()->with('203.0.113.9')->andReturn(['success' => true]);
        $rule = RemoteMysqlAccessRule::query()->create([
            'id' => (string) Str::uuid(),
            'ip_address' => '203.0.113.9',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAsPanelUser($admin)
            ->deleteJson(route('databases.remote-access.destroy', ['id' => $rule->id]))
            ->assertOk();

        $this->assertNull($rule->fresh());
    }

    private function notification(): Notification
    {
        return Notification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'system',
            'status' => 'info',
            'title' => 'Hello',
            'message' => 'World',
        ]);
    }

    private function reflectAction(Route $route): ?ReflectionFunctionAbstract
    {
        $uses = $route->getAction('uses');
        if ($uses instanceof Closure) {
            return new ReflectionFunction($uses);
        }
        if (! is_string($uses) || ! str_contains($uses, '@') || str_starts_with($uses, 'Illuminate\\')) {
            return null;
        }

        return new ReflectionMethod(...explode('@', $uses));
    }

    /**
     * Parameters that take a route value, leaving out services the container injects.
     *
     * @return array<int, string>
     */
    private function positionalParameters(ReflectionFunctionAbstract $action): array
    {
        $names = [];
        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();
            $service = $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && ! is_subclass_of($type->getName(), Model::class);
            if (! $service) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }
}
