<?php

namespace Tests\Feature;

use App\Jobs\RunWebsiteGitActionJob;
use App\Models\GithubAccount;
use App\Models\GithubOAuthApp;
use App\Models\WebsiteGitDeployment;
use App\Models\WebsiteGitLog;
use App\Services\Github\GithubClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Builds only the tables this feature touches (plus its own migration), so it
 * runs independently of the full migration history.
 */
class GithubIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->timestamps();
        });
        (require database_path('migrations/2026_08_06_120000_create_website_git_deployments_table.php'))->up();
        (require database_path('migrations/2026_09_27_000000_create_github_integration_tables.php'))->up();
    }

    public function test_authorization_url_requests_scopes_and_account_picker(): void
    {
        $app = new GithubOAuthApp(['client_id' => 'Ov23liTEST']);
        $url = app(GithubClient::class)->authorizationUrl($app, 'https://panel.test/github/oauth/callback', 'state123');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('Ov23liTEST', $query['client_id']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('repo admin:repo_hook read:user', $query['scope']);
        $this->assertSame('state123', $query['state']);
    }

    public function test_oauth_callback_links_multiple_accounts_to_one_user(): void
    {
        $userId = $this->user();
        GithubOAuthApp::create(['client_id' => 'cid', 'client_secret' => 'secret']);

        $logins = [[101, 'personal'], [202, 'work'], [101, 'personal']];
        $tokens = Http::sequence();
        $users = Http::sequence();
        foreach ($logins as [$githubId, $login]) {
            $tokens->push(['access_token' => "tok-{$login}", 'scope' => 'repo']);
            $users->push(['id' => $githubId, 'login' => $login, 'name' => null, 'avatar_url' => null]);
        }
        Http::fake(['github.com/login/oauth/access_token' => $tokens, 'api.github.com/user' => $users]);

        foreach ($logins as $ignored) {
            $this->withSession(['github_oauth' => ['state' => 'abc', 'panel_token' => str_repeat('a', 64), 'user_id' => $userId, 'website_id' => null]])
                ->get('/github/oauth/callback?state=abc&code=xyz')
                ->assertRedirect();
        }

        $accounts = GithubAccount::query()->where('user_id', $userId)->orderBy('login')->get();
        $this->assertSame(['personal', 'work'], $accounts->pluck('login')->all());
        $this->assertSame('tok-work', $accounts[1]->access_token);
    }

    public function test_oauth_callback_rejects_mismatched_state(): void
    {
        $userId = $this->user();
        GithubOAuthApp::create(['client_id' => 'cid', 'client_secret' => 'secret']);
        Http::fake();

        $this->withSession(['github_oauth' => ['state' => 'abc', 'panel_token' => str_repeat('a', 64), 'user_id' => $userId]])
            ->get('/github/oauth/callback?state=evil&code=xyz')
            ->assertRedirect();

        $this->assertSame(0, GithubAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_expiring_token_is_refreshed(): void
    {
        GithubOAuthApp::create(['client_id' => 'cid', 'client_secret' => 'secret']);
        $account = GithubAccount::create([
            'user_id' => $this->user(), 'github_id' => 1, 'login' => 'octo',
            'access_token' => 'old', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addMinute(),
        ]);
        Http::fake(['github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'new', 'refresh_token' => 'refresh-2', 'expires_in' => 28800,
        ])]);

        $this->assertSame('new', app(GithubClient::class)->tokenFor($account));
        $this->assertSame('refresh-2', $account->fresh()->refresh_token);
    }

    public function test_push_webhook_requires_valid_signature(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        $this->postJson("/webhooks/git/{$deployment->id}", ['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256=bad'])
            ->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_push_to_deployed_branch_queues_a_pull(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        $this->signedPush($deployment, ['ref' => 'refs/heads/main', 'after' => str_repeat('f', 40)])->assertStatus(202);
        Queue::assertPushed(RunWebsiteGitActionJob::class, fn ($job) => $job->deploymentId === $deployment->id && $job->action === 'pull');
    }

    public function test_push_to_other_branch_or_before_first_deploy_is_ignored(): void
    {
        Queue::fake();
        $deployment = $this->deployment();
        $this->signedPush($deployment, ['ref' => 'refs/heads/feature'])->assertOk();

        $fresh = $this->deployment(cloned: false);
        $this->signedPush($fresh, ['ref' => 'refs/heads/main'])->assertOk();

        Queue::assertNothingPushed();
    }

    private function signedPush(WebsiteGitDeployment $deployment, array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', "/webhooks/git/{$deployment->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        ], $body);
    }

    private function deployment(bool $cloned = true): WebsiteGitDeployment
    {
        $websiteId = (string) Str::uuid();
        \DB::table('websites')->insert(['id' => $websiteId]);
        $deployment = WebsiteGitDeployment::create([
            'website_id' => $websiteId, 'repository_url' => 'https://github.com/o/r.git', 'branch' => 'main',
            'webhook_secret' => 'hook-secret', 'deploy_on_push' => true, 'enabled' => true,
        ]);
        if ($cloned) {
            WebsiteGitLog::create(['deployment_id' => $deployment->id, 'action' => 'clone', 'status' => 'success']);
        }

        return $deployment;
    }

    private function user(): int
    {
        return (int) \DB::table('users')->insertGetId(['name' => 'Tester']);
    }
}
