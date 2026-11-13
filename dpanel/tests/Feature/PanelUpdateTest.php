<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\Notification;
use App\Models\PanelUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Backlog U6: updating dPanel and rolling it back from System → Updates. */
class PanelUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const RUNS = 'drust.test/api/v1/panel-update/runs';

    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
            'app.version' => '1.0.0',
            'panel_update.repo' => 'me/dpanel',
            'panel_update.release_ref' => 'v1.0.0',
            'panel_update.release_commit' => self::COMMIT,
            'panel_update.github_api' => 'https://github.test',
        ]);
        $this->session = str_repeat('c', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, ['token' => $this->session, ...$params]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** A run as drust's API reports it. */
    private static function drustRun(string $id, string $status, array $more = []): array
    {
        return ['id' => $id, 'action' => 'update', 'status' => $status, 'step' => 'done', 'target_ref' => 'v1.2.0',
            'from_version' => '1.0.0', 'from_ref' => 'v1.0.0', 'started_at' => '2026-11-11T10:00:00Z', 'has_backup' => true, ...$more];
    }

    private function fakeDrust(array $runs = [], ?array $started = null, int $startStatus = 200): void
    {
        $active = collect($runs)->first(fn ($run) => in_array($run['status'], PanelUpdate::ACTIVE, true));
        Http::fake([
            self::RUNS.'/*' => Http::response(['success' => true, 'run' => $runs[0] ?? null, 'log' => "line one\nline two\n", 'offset' => 18]),
            self::RUNS => function (Request $request) use ($runs, $active, $started, $startStatus) {
                if ($request->method() === 'POST') {
                    return $startStatus === 200
                        ? Http::response(['success' => true, 'message' => 'Started.', 'run' => $started])
                        : Http::response(['success' => false, 'message' => 'Run 20261111-100000-0001 is still going.'], $startStatus);
                }

                return Http::response(['success' => true, 'active' => $active['id'] ?? null, 'runs' => $runs]);
            },
        ]);
    }

    public function test_only_admins_see_and_use_the_update_center(): void
    {
        $this->fakeDrust();
        $reseller = User::factory()->create(['role' => 'reseller']);

        $this->actingAs($reseller)->get($this->url('system.updates.index'))->assertForbidden();
        $this->actingAs($reseller)->postJson($this->url('system.updates.update'), ['target_ref' => 'v1.2.0'])->assertForbidden();
        $this->actingAs($reseller)->postJson($this->url('system.updates.rollback'))->assertForbidden();
        $this->actingAs($reseller)->getJson($this->url('system.updates.status'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_page_shows_the_running_version_and_the_history(): void
    {
        $this->fakeDrust([self::drustRun('20261110-100000-0001', 'succeeded', ['to_version' => '1.2.0'])]);

        $this->actingAs($this->admin())
            ->get($this->url('system.updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('System/Updates/Index')
                ->where('current.version', '1.0.0')
                ->where('current.ref', 'v1.0.0')
                ->where('current.commit', self::COMMIT)
                ->where('repo', 'me/dpanel')
                ->where('runs.0.run_id', '20261110-100000-0001')
                ->where('runs.0.to_version', '1.2.0'));

        $this->assertSame('succeeded', PanelUpdate::query()->sole()->status);
    }

    public function test_the_check_finds_a_newer_release_and_lists_its_commits(): void
    {
        Http::fake([
            'github.test/repos/me/dpanel/tags*' => Http::response([
                ['name' => 'v1.0.0'], ['name' => 'v1.10.0'], ['name' => 'nightly'], ['name' => 'v1.2.0'],
            ]),
            'github.test/repos/me/dpanel/compare/*' => Http::response([
                'ahead_by' => 2,
                'html_url' => 'https://github.com/me/dpanel/compare/x...v1.10.0',
                'commits' => [
                    ['sha' => 'bbbbbbbbbbbb', 'html_url' => 'https://github.com/c/1', 'commit' => ['message' => "Fix login\n\nLong body", 'author' => ['name' => 'Ann', 'date' => '2026-11-01T00:00:00Z']]],
                    ['sha' => 'cccccccccccc', 'html_url' => 'https://github.com/c/2', 'commit' => ['message' => 'Add updates page', 'author' => ['name' => 'Bob', 'date' => '2026-11-02T00:00:00Z']]],
                ],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->getJson($this->url('system.updates.check'))
            ->assertOk()
            ->assertJsonPath('latest', 'v1.10.0')
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('tags', ['v1.10.0', 'v1.2.0', 'v1.0.0'])
            ->assertJsonPath('ahead_by', 2)
            ->assertJsonPath('commits.0.message', 'Add updates page')
            ->assertJsonPath('commits.1.message', 'Fix login')
            ->assertJsonPath('commits.1.sha', 'bbbbbbb');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'compare/'.self::COMMIT.'...v1.10.0'));
    }

    public function test_the_check_says_when_the_latest_release_is_installed_and_when_github_fails(): void
    {
        Http::fake([
            'github.test/repos/me/dpanel/tags*' => Http::sequence()
                ->push([['name' => 'v1.0.0']])
                ->push('rate limited', 403),
            'github.test/repos/me/dpanel/compare/*' => Http::response(['ahead_by' => 0, 'commits' => []]),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->url('system.updates.check'))
            ->assertOk()->assertJsonPath('latest', 'v1.0.0')->assertJsonPath('update_available', false);

        $this->actingAs($admin)->getJson($this->url('system.updates.check', ['refresh' => 1]))
            ->assertStatus(502)->assertJsonPath('update_available', false);
    }

    public function test_an_update_starts_in_drust_and_is_recorded(): void
    {
        $admin = $this->admin();
        $this->fakeDrust([], self::drustRun('20261111-100000-0001', 'queued', ['step' => 'queued', 'has_backup' => false]));

        $this->actingAs($admin)
            ->postJson($this->url('system.updates.update'), ['target_ref' => 'v1.2.0'])
            ->assertOk()
            ->assertJsonPath('run.run_id', '20261111-100000-0001')
            ->assertJsonPath('run.status', 'queued');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://drust.test/api/v1/panel-update/runs'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['action'] === 'update'
            && $request['target_ref'] === 'v1.2.0'
            && $request['repo'] === 'me/dpanel');
        $row = PanelUpdate::query()->sole();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('v1.2.0', $row->target_ref);
    }

    public function test_a_second_update_is_refused_while_one_runs(): void
    {
        $this->fakeDrust([self::drustRun('20261111-100000-0001', 'running', ['step' => 'install'])]);

        $this->actingAs($this->admin())
            ->postJson($this->url('system.updates.update'), ['target_ref' => 'v1.2.0'])
            ->assertStatus(409);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_drust_refusing_a_busy_server_is_passed_on(): void
    {
        $this->fakeDrust([], null, 409);

        $this->actingAs($this->admin())->postJson($this->url('system.updates.update'), ['target_ref' => 'v1.2.0'])
            ->assertStatus(409)->assertJsonPath('message', 'Run 20261111-100000-0001 is still going.');
        $this->assertSame(0, PanelUpdate::query()->count());
    }

    public function test_a_server_that_cannot_update_itself_says_so(): void
    {
        Http::fake([self::RUNS => Http::response(['error' => 'unsupported', 'capability' => 'services'], 501)]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->url('system.updates.update'), ['target_ref' => 'v1.2.0'])
            ->assertStatus(501);
        $this->actingAs($admin)->get($this->url('system.updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('drustError', 'Updating from the panel is not available on this server.'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_a_target_that_is_not_a_ref_is_refused(): void
    {
        $this->fakeDrust();
        foreach (['--upload-pack=x', 'a b', '../x', ''] as $bad) {
            $this->actingAs($this->admin())
                ->postJson($this->url('system.updates.update'), ['target_ref' => $bad])
                ->assertJsonValidationErrors('target_ref');
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_a_rollback_passes_on_the_database_choice(): void
    {
        $this->fakeDrust([self::drustRun('20261110-100000-0001', 'succeeded')], self::drustRun('20261111-100000-0002', 'queued', ['action' => 'rollback', 'rollback_of' => '20261110-100000-0001']));

        $this->actingAs($this->admin())
            ->postJson($this->url('system.updates.rollback'), ['run_id' => '20261110-100000-0001', 'restore_database' => false])
            ->assertOk()
            ->assertJsonPath('run.action', 'rollback');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['action'] === 'rollback'
            && $request['from_run'] === '20261110-100000-0001'
            && $request['restore_database'] === false);
    }

    public function test_the_status_follows_the_log_and_tells_admins_the_result_once(): void
    {
        $this->fakeDrust([
            self::drustRun('20261111-100000-0001', 'rolled_back', ['message' => 'Migrations failed. The previous version was restored.', 'finished_at' => now()->subMinute()->toIso8601String()]),
            // Old news that a restored database brought back: no notification.
            self::drustRun('20261001-100000-0001', 'succeeded', ['finished_at' => now()->subDays(40)->toIso8601String()]),
        ]);
        PanelUpdate::query()->create(['run_id' => '20261111-100000-0001', 'action' => 'update', 'status' => 'running']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson($this->url('system.updates.status', ['run' => '20261111-100000-0001', 'offset' => 0]))
            ->assertOk()
            ->assertJsonPath('runs.0.status', 'rolled_back')
            ->assertJsonPath('active', null)
            ->assertJsonPath('log.text', "line one\nline two\n")
            ->assertJsonPath('log.offset', 18);
        $this->actingAs($admin)->getJson($this->url('system.updates.status'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://drust.test/api/v1/panel-update/runs/20261111-100000-0001?offset=0');
        $notifications = Notification::query()->where('type', 'panel_update')->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('warning', $notifications[0]->status);
        $this->assertStringContainsString('previous version was restored', (string) $notifications[0]->message);
        $this->assertNotNull(PanelUpdate::query()->where('run_id', '20261111-100000-0001')->sole()->notified_at);
        $this->assertSame(2, PanelUpdate::query()->count());
    }

    public function test_the_scheduled_sync_only_asks_drust_while_a_run_is_unfinished(): void
    {
        $this->fakeDrust([self::drustRun('20261111-100000-0001', 'succeeded', ['to_version' => '1.2.0', 'finished_at' => now()->toIso8601String()])]);

        $this->artisan('panel:update-sync')->assertSuccessful();
        Http::assertNothingSent();

        PanelUpdate::query()->create(['run_id' => '20261111-100000-0001', 'action' => 'update', 'status' => 'running']);
        $this->artisan('panel:update-sync')->assertSuccessful();

        $this->assertSame('succeeded', PanelUpdate::query()->sole()->status);
        $this->assertSame('completed', Notification::query()->where('type', 'panel_update')->sole()->status);
    }
}
