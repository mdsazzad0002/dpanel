<?php

namespace Tests\Feature\Git;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\Website\WebsiteGitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GitUserLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://daemon.test']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function deployment(User $reseller, ?User $creator): WebsiteGitDeployment
    {
        $website = Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test', 'scope' => 'local',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'start_directory' => '',
            'site_owner' => 'shop', 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
            'assigned_reseller_id' => $reseller->id,
        ]);
        $deployment = WebsiteGitDeployment::create([
            'website_id' => $website->id, 'repository_url' => 'https://gitlab.com/o/r.git', 'branch' => 'main',
            'auth_username' => 'owner', 'auth_token' => 'owner-token', 'enabled' => true, 'created_by' => $creator?->id,
        ]);
        $deployment->logs()->create(['action' => 'clone', 'status' => 'success', 'message' => 'Cloned.']);

        return $deployment;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function url(WebsiteGitDeployment $deployment, string $path = ''): string
    {
        return "/cpsess{$this->token}/websites/{$deployment->website_id}/git{$path}";
    }

    public function test_another_user_must_link_before_pulling(): void
    {
        $reseller = $this->user('reseller');
        $deployment = $this->deployment($reseller, $this->user('admin'));
        Http::fake();

        $this->as($reseller)->get($this->url($deployment))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('access.connected', false)->where('access.via', null));
        $this->as($reseller)->postJson($this->url($deployment, '/run'), ['action' => 'pull'])
            ->assertForbidden()->assertJsonPath('needs_link', true);

        Http::assertNothingSent();
    }

    public function test_linking_checks_access_then_pulls_with_the_users_own_token(): void
    {
        $reseller = $this->user('reseller');
        $deployment = $this->deployment($reseller, $this->user('admin'));
        Http::fake(['daemon.test/*' => Http::sequence()
            ->push(['success' => true, 'data' => ['output' => json_encode(['remote_empty' => false, 'branch_exists' => true])]])
            ->push(['success' => true, 'data' => ['output' => 'Already up to date.']])
            ->push(['success' => true, 'data' => ['output' => 'Already up to date.']])]);

        $this->as($reseller)->postJson($this->url($deployment, '/link'), ['auth_username' => 'me', 'auth_token' => 'my-token'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('access.via', 'link');
        $this->as($reseller)->postJson($this->url($deployment, '/run'), ['action' => 'pull'])->assertOk();

        $sent = Http::recorded()->map(fn ($pair) => [$pair[0]['action'], $pair[0]['token']])->all();
        $this->assertSame([['probe', 'my-token'], ['pull', 'my-token'], ['pull', 'my-token']], $sent);
        $this->assertSame('owner-token', $deployment->refresh()->auth_token);
    }

    public function test_a_token_without_repository_access_is_not_linked(): void
    {
        $reseller = $this->user('reseller');
        $deployment = $this->deployment($reseller, $this->user('admin'));
        Http::fake(['daemon.test/*' => Http::response(['success' => false, 'message' => 'Authentication failed'], 422)]);

        $this->as($reseller)->postJson($this->url($deployment, '/link'), ['auth_token' => 'wrong'])
            ->assertStatus(422);
        $this->assertSame(0, $deployment->userLinks()->count());
    }

    public function test_admins_and_the_connecting_user_work_without_linking(): void
    {
        $reseller = $this->user('reseller');
        $admin = $this->user('admin');
        Http::fake(['daemon.test/*' => Http::response(['success' => true, 'data' => ['output' => 'Already up to date.']])]);

        $byAdmin = $this->deployment($reseller, $admin);
        $this->as($this->user('admin'))->postJson($this->url($byAdmin, '/run'), ['action' => 'pull'])->assertOk();

        $byReseller = WebsiteGitDeployment::query()->whereKey($byAdmin->id)->first();
        $byReseller->forceFill(['created_by' => $reseller->id])->save();
        $this->as($reseller)->postJson($this->url($byReseller, '/run'), ['action' => 'pull'])->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request['token'] === 'owner-token');
    }

    public function test_scheduled_runs_keep_using_the_deployments_own_credentials(): void
    {
        $reseller = $this->user('reseller');
        $deployment = $this->deployment($reseller, $this->user('admin'));
        $deployment->userLinks()->create(['user_id' => $reseller->id, 'auth_token' => 'my-token']);
        Http::fake(['daemon.test/*' => Http::response(['success' => true, 'data' => ['output' => 'ok']])]);

        app(WebsiteGitService::class)->run($deployment, 'pull', null, 'Automated website sync');

        Http::assertSent(fn (HttpRequest $request) => $request['token'] === 'owner-token');
    }
}
