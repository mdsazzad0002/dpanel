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

class SecurityCenterAccessTest extends TestCase
{
    use RefreshDatabase;

    private function allowed(User $user): bool
    {
        $request = Request::create('/security/center');
        $request->setUserResolver(fn () => $user);
        $middleware = Route::getRoutes()->getByName('security.center')->gatherMiddleware();
        $this->assertContains('role_or_permission:admin|view_security_center', $middleware);

        try {
            (new CheckRoleOrPermission())->handle($request, fn () => response('ok'), 'admin|view_security_center');

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

    public function test_security_center_is_admin_only_until_a_role_is_granted_access(): void
    {
        $this->assertTrue($this->allowed($this->user('admin')));
        $reseller = $this->user('reseller');
        $this->assertFalse($this->allowed($reseller));
        $this->assertFalse($this->allowed($this->user('general')));

        $role = RolePermissions::ensureRole('reseller');
        $role->permissions_csv .= ',view_security_center';
        $role->save();
        $this->assertTrue($this->allowed($reseller->refresh()));
    }
}
