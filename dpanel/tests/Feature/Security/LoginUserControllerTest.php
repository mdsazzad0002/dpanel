<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LoginUserControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('e', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        $this->admin = User::factory()->create(['id' => 1]);
        $this->admin->assignRole('admin');
    }

    private function asAdmin(): static
    {
        return $this->withoutMiddleware()->actingAs($this->admin)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_it_lists_users_from_drust(): void
    {
        Http::fake(['drust.test/api/v1/login-users' => Http::response([
            'success' => true,
            'data' => ['users' => [['name' => 'root', 'uid' => 0, 'can_login' => true]], 'shells' => ['/bin/bash']],
        ])]);

        $this->asAdmin()->getJson("/dpt{$this->token}/security/login-users/list")
            ->assertOk()
            ->assertJsonPath('users.0.name', 'root')
            ->assertJsonPath('shells.0', '/bin/bash');
    }

    public function test_it_forwards_a_shell_change_to_drust(): void
    {
        Http::fake(['drust.test/api/v1/login-users' => Http::response(['success' => true, 'message' => 'site1 can no longer open a shell.'])]);

        $this->asAdmin()->postJson("/dpt{$this->token}/security/login-users", [
            'action' => 'set_shell', 'user' => 'site1', 'shell' => '/usr/sbin/nologin',
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['user'] === 'site1'
            && $request['shell'] === '/usr/sbin/nologin');
    }

    public function test_it_rejects_invalid_usernames_without_calling_drust(): void
    {
        Http::fake();

        $this->asAdmin()->postJson("/dpt{$this->token}/security/login-users", [
            'action' => 'lock', 'user' => 'root; rm -rf /',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_admins_other_than_user_one_are_forbidden(): void
    {
        Http::fake();
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->withoutMiddleware()->actingAs($other)->withSession(['panel_session_token' => $this->token])
            ->getJson("/dpt{$this->token}/security/login-users/list")
            ->assertForbidden();
        $this->withoutMiddleware()->actingAs($other)->withSession(['panel_session_token' => $this->token])
            ->postJson("/dpt{$this->token}/security/login-users", ['action' => 'lock', 'user' => 'site1'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_drust_errors_are_reported(): void
    {
        Http::fake(['drust.test/api/v1/login-users' => Http::response(['success' => false, 'message' => 'root must keep a login shell'])]);

        $this->asAdmin()->postJson("/dpt{$this->token}/security/login-users", [
            'action' => 'set_shell', 'user' => 'root', 'shell' => '/usr/sbin/nologin',
        ])->assertStatus(422)->assertJson(['message' => 'root must keep a login shell']);
    }
}
