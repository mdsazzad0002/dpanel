<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRoleOrPermission;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MailPagesAccessTest extends TestCase
{
    use RefreshDatabase;

    private function allowed(User $user, string $routeName): bool
    {
        $middleware = collect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->first(fn ($m) => is_string($m) && str_starts_with($m, 'role_or_permission:'));
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        try {
            (new CheckRoleOrPermission())->handle($request, fn () => response('ok'), substr($middleware, strlen('role_or_permission:')));

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->refresh();
    }

    public function test_dns_guide_and_mail_health_need_a_grant_for_normal_users(): void
    {
        $general = $this->user('general');
        foreach (['emails.guide', 'emails.guide.verify', 'mail-health.index'] as $route) {
            $this->assertTrue($this->allowed($this->user('admin'), $route), $route);
            $this->assertTrue($this->allowed($this->user('reseller'), $route), $route);
            $this->assertFalse($this->allowed($general, $route), $route);
        }
        $this->assertTrue($this->allowed($general, 'emails.list'));

        $role = RolePermissions::ensureRole('general');
        $role->permissions_csv .= ',manage_mail_dns,view_mail_health';
        $role->save();
        $this->assertTrue($this->allowed($general->refresh(), 'emails.guide'));
        $this->assertTrue($this->allowed($general, 'mail-health.index'));
    }

    public function test_mailbox_owners_can_reach_every_webmail_route(): void
    {
        $general = $this->user('general');
        $routes = collect(Route::getRoutes()->getRoutesByName())->keys()
            ->filter(fn (string $name) => str_starts_with($name, 'mailbox.'))
            ->merge(['emails.login', 'emails.data', 'emails.send', 'emails.delete-message']);

        $this->assertGreaterThan(15, $routes->count());
        foreach ($routes as $route) {
            $this->assertTrue($this->allowed($general, $route), $route);
        }

        $role = RolePermissions::ensureRole('general');
        $role->permissions_csv = str_replace('manage_email', '', (string) $role->permissions_csv);
        $role->save();
        $this->assertFalse($this->allowed($general->refresh(), 'mailbox.open'));
    }
}
