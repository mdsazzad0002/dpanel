<?php

namespace Tests\Feature\Git;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Router;
use App\Services\Mcp\McpServer;
use App\Services\Website\GitConflictAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GitConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private const CONFLICT = [
        'path' => 'app/config.php', 'kind' => 'text', 'base' => "a\nb\n", 'live' => "a\nLIVE\n", 'remote' => "a\nDEV\n",
        'merged' => "a\n<<<<<<< live\nLIVE\n=======\nDEV\n>>>>>>> remote\n", 'live_deleted' => false, 'remote_deleted' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://daemon.test']);
    }

    private function deployment(array $attributes = []): WebsiteGitDeployment
    {
        $website = Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test', 'scope' => 'local',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'start_directory' => '',
            'site_owner' => 'shop', 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
        ]);

        return WebsiteGitDeployment::create($attributes + [
            'website_id' => $website->id, 'repository_url' => 'https://github.com/o/r.git', 'branch' => 'main',
            'auth_username' => 'dev', 'auth_token' => 'secret-token', 'enabled' => true,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function daemon(string $output, bool $success = true): void
    {
        Http::fake(['daemon.test/*' => Http::response(['success' => $success, 'message' => $output, 'data' => ['output' => $output]], $success ? 200 : 422)]);
    }

    public function test_a_stopped_sync_is_flagged_and_conflicts_can_be_listed(): void
    {
        $deployment = $this->deployment(['last_status' => 'failed', 'last_message' => 'Remote and local files both changed; sync stopped before conflict.']);

        $this->as($this->admin())->get("/cpsess{$this->token}/websites/{$deployment->website_id}/git")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsResolution', true)->where('aiAvailable', false));

        $this->daemon(json_encode(['behind' => true, 'ahead' => true, 'dirty' => true, 'conflicts' => [self::CONFLICT]]));
        $this->as($this->admin())->postJson("/cpsess{$this->token}/websites/{$deployment->website_id}/git/conflicts")
            ->assertOk()->assertJsonPath('conflicts.0.path', 'app/config.php')->assertJsonPath('ai_available', false);
        Http::assertSent(fn (HttpRequest $request) => $request['action'] === 'conflicts' && $request['target'] === '/home/shop/public_html');
    }

    public function test_resolving_sends_the_strategy_and_per_file_choices(): void
    {
        $deployment = $this->deployment();
        $this->daemon('Merged the repository into the live site and pushed.');

        $this->as($this->admin())->postJson("/cpsess{$this->token}/websites/{$deployment->website_id}/git/resolve", [
            'strategy' => 'custom',
            'resolutions' => [
                ['path' => 'app/config.php', 'content' => "a\nMERGED\n"],
                ['path' => 'logo.png', 'take' => 'live'],
            ],
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('needsResolution', false);

        Http::assertSent(fn (HttpRequest $request) => $request['action'] === 'resolve'
            && $request['strategy'] === 'custom'
            && $request['token'] === 'secret-token'
            && json_decode($request->body(), true)['resolutions'] === [
                'app/config.php' => ['content' => "a\nMERGED\n"],
                'logo.png' => ['take' => 'live'],
            ]);
        $this->assertDatabaseHas('website_git_logs', ['deployment_id' => $deployment->id, 'action' => 'resolve', 'status' => 'success']);

        $this->as($this->admin())->postJson("/cpsess{$this->token}/websites/{$deployment->website_id}/git/resolve", ['strategy' => 'ours'])
            ->assertStatus(422);
        $this->as($this->admin())->postJson("/cpsess{$this->token}/websites/{$deployment->website_id}/git/resolve", ['strategy' => 'custom'])
            ->assertStatus(422);
    }

    public function test_ai_suggestions_are_cleaned_and_never_applied_on_their_own(): void
    {
        $router = $this->mock(Router::class);
        $router->shouldReceive('candidates')->andReturn(collect([new \stdClass]));
        $gateway = $this->mock(AiGatewayService::class);
        $gateway->shouldReceive('chatAuto')->once()->withArgs(fn ($model, $messages, $options) => str_contains($messages[0]['content'], "=== LIVE ===\na\nLIVE")
            && $options['operation'] === 'git_conflict_resolve')
            ->andReturn(['content' => "```php\na\nLIVE\nDEV\n```"]);

        $ai = app(GitConflictAi::class);
        $this->assertTrue($ai->available());
        $website = new Website(['domain' => 'shop.test']);
        $result = $ai->suggest($website, [self::CONFLICT, ['path' => 'logo.png', 'kind' => 'binary']]);

        $this->assertSame(['app/config.php' => "a\nLIVE\nDEV\n"], $result['suggestions']);
        $this->assertStringContainsString('Binary', $result['skipped']['logo.png']);
    }

    public function test_ai_answers_with_conflict_markers_are_rejected(): void
    {
        $this->mock(Router::class)->shouldReceive('candidates')->andReturn(collect([new \stdClass]));
        $this->mock(AiGatewayService::class)->shouldReceive('chatAuto')->andReturn(['content' => self::CONFLICT['merged']]);

        $result = app(GitConflictAi::class)->suggest(new Website(['domain' => 'shop.test']), [self::CONFLICT]);

        $this->assertSame([], $result['suggestions']);
        $this->assertArrayHasKey('app/config.php', $result['skipped']);
    }

    public function test_the_ai_chat_has_git_conflict_skills(): void
    {
        $admin = $this->admin();
        $deployment = $this->deployment();
        $tools = app(McpServer::class)->tools();
        $this->assertTrue($tools['list_git_conflicts']->readOnly());
        $this->assertFalse($tools['resolve_git_conflicts']->readOnly());

        $token = McpAccessToken::query()->create([
            'user_id' => $admin->id, 'name' => 'test', 'token_prefix' => 'x', 'token_hash' => hash('sha256', 'x'),
            'scope' => McpAccessToken::SCOPE_FULL, 'is_active' => true,
        ]);
        $this->daemon('Merged the repository into the live site and pushed.');
        $result = $tools['resolve_git_conflicts']->handle([
            'domain' => 'shop.test', 'strategy' => 'custom',
            'files' => [['path' => 'app/config.php', 'content' => "a\nMERGED\n"]],
        ], $token);

        $this->assertTrue($result['resolved']);
        Http::assertSent(fn (HttpRequest $request) => $request['action'] === 'resolve'
            && json_decode($request->body(), true)['resolutions']['app/config.php']['content'] === "a\nMERGED\n");
        $this->assertSame('success', $deployment->fresh()->last_status);
    }
}
