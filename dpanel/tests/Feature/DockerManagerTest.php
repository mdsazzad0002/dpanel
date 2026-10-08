<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Networks, volumes, stacks, overview and the deeper container tools. */
class DockerManagerTest extends TestCase
{
    private string $token;

    private User $admin;

    private array $status = [
        'installed' => true,
        'running' => true,
        'version' => '27.3.1',
        'containers' => [['id' => '3f2a', 'name' => 'web', 'image' => 'nginx', 'state' => 'running']],
        'images' => [],
        'networks' => ['bridge', 'search'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 128)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        config([
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
        ]);
    }

    public function test_every_section_has_its_page(): void
    {
        foreach (['overview' => 'Overview', 'stacks' => 'Stacks', 'networks' => 'Networks', 'volumes' => 'Volumes', 'templates' => 'Templates'] as $path => $component) {
            $this->request()
                ->get("/cpsess{$this->token}/docker/{$path}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component("Docker/{$component}"));
        }
    }

    public function test_every_docker_route_is_admin_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'docker.'));

        $this->assertGreaterThan(25, $routes->count());
        foreach ($routes as $route) {
            $this->assertContains('role:admin', $route->gatherMiddleware(), $route->getName().' must be admin-only');
        }
    }

    public function test_recreate_sends_the_new_spec_with_advanced_options(): void
    {
        Http::fake(['drust.test/api/v1/docker' => Http::response(['success' => true, 'message' => 'Container es recreated.', 'data' => $this->status])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/recreate", [
                'id' => 'es',
                'spec' => [
                    'image' => 'elasticsearch:8.15.3',
                    'name' => 'es',
                    'network' => 'search',
                    'aliases' => ['elasticsearch', ''],
                    'memory' => '1g',
                    'cpus' => '1.5',
                    'command' => ['--flag'],
                    'env' => [['key' => 'discovery.type', 'value' => 'single-node'], ['key' => 'ELASTIC_PASSWORD', 'value' => 'hunter2']],
                ],
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['action'] === 'recreate'
            && $request['id'] === 'es'
            && $request['spec']['network'] === 'search'
            && $request['spec']['aliases'] === ['elasticsearch']
            && $request['spec']['memory'] === '1g'
            && $request['spec']['command'] === ['--flag']
            && $request['spec']['env'][0]['key'] === 'discovery.type'
            && $request['spec']['pull'] === false);
        $log = ActivityLog::query()->sole();
        $this->assertSame('docker.recreate', $log->action);
        $this->assertStringNotContainsString('hunter2', json_encode($log->properties));
    }

    public function test_bad_limits_are_rejected_before_drust(): void
    {
        Http::fake();

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/run", ['image' => 'nginx', 'memory' => 'lots'])
            ->assertStatus(422);
        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/run", ['image' => 'nginx', 'network' => '--host'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_bulk_runs_each_container_and_reports_failures(): void
    {
        Http::fake(function ($request) {
            if ($request['id'] === 'broken') {
                return Http::response(['success' => false, 'message' => 'Failed: No such container: broken']);
            }

            return Http::response(['success' => true, 'message' => 'ok', 'data' => $this->status]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/bulk", ['action' => 'restart', 'ids' => ['web', 'broken', 'web']])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.containers.0.name', 'web');

        Http::assertSentCount(2);
        $this->assertSame('docker.bulk_restart', ActivityLog::query()->sole()->action);
    }

    public function test_exec_is_sent_and_its_command_logged(): void
    {
        Http::fake(['drust.test/api/v1/docker/exec' => Http::response(['success' => true, 'message' => 'Command finished.', 'data' => ['output' => "hi\n", 'exit_code' => 0, 'timed_out' => false]])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/exec", ['id' => 'web', 'command' => 'echo hi', 'workdir' => '/app'])
            ->assertOk()
            ->assertJsonPath('data.output', "hi\n");

        Http::assertSent(fn ($request) => $request['command'] === 'echo hi' && $request['workdir'] === '/app' && $request['user'] === '');
        $this->assertSame('echo hi', ActivityLog::query()->sole()->properties['command']);
    }

    public function test_inspect_and_stats_are_read_from_drust(): void
    {
        Http::fake([
            'drust.test/api/v1/docker/inspect' => Http::response(['success' => true, 'message' => 'ok', 'data' => ['name' => 'web', 'spec' => ['image' => 'nginx']]]),
            'drust.test/api/v1/docker/stats' => Http::response(['success' => true, 'message' => 'ok', 'data' => ['stats' => [['name' => 'web', 'cpu' => '0.5%']]]]),
        ]);

        $this->request()->postJson("/cpsess{$this->token}/docker/containers/inspect", ['id' => 'web'])->assertOk()->assertJsonPath('data.spec.image', 'nginx');
        $this->request()->getJson("/cpsess{$this->token}/docker/containers/stats")->assertOk()->assertJsonPath('data.stats.0.cpu', '0.5%');
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_network_create_and_connect(): void
    {
        Http::fake(['drust.test/api/v1/docker/networks' => Http::response(['success' => true, 'message' => 'ok', 'data' => ['networks' => []]])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/networks/action", ['action' => 'create', 'name' => 'search', 'internal' => true, 'subnet' => '10.20.0.0/24'])
            ->assertOk();
        $this->request()
            ->postJson("/cpsess{$this->token}/docker/networks/action", ['action' => 'connect', 'name' => 'search', 'container' => 'web', 'aliases' => ['es']])
            ->assertOk();
        $this->request()
            ->postJson("/cpsess{$this->token}/docker/networks/action", ['action' => 'connect', 'name' => 'search'])
            ->assertStatus(422);

        Http::assertSent(fn ($request) => $request['action'] === 'create' && $request['spec'] === ['name' => 'search', 'internal' => true, 'subnet' => '10.20.0.0/24']);
        Http::assertSent(fn ($request) => $request['action'] === 'connect' && $request['container'] === 'web' && $request['aliases'] === ['es']);
        $this->assertSame(['docker.network.create', 'docker.network.connect'], ActivityLog::query()->orderBy('created_at')->pluck('action')->all());
    }

    public function test_volume_actions(): void
    {
        Http::fake(['drust.test/api/v1/docker/volumes' => Http::response(['success' => true, 'message' => 'ok', 'data' => ['volumes' => []]])]);

        $this->request()->postJson("/cpsess{$this->token}/docker/volumes/action", ['action' => 'remove', 'name' => 'pg-data'])->assertOk();
        $this->request()->postJson("/cpsess{$this->token}/docker/volumes/action", ['action' => 'prune', 'all' => true])->assertOk();
        $this->request()->postJson("/cpsess{$this->token}/docker/volumes/action", ['action' => 'remove', 'name' => '-rf'])->assertStatus(422);

        Http::assertSent(fn ($request) => $request['action'] === 'prune' && $request['all'] === true);
        Http::assertSentCount(2);
    }

    public function test_stack_files_are_sent_but_never_logged(): void
    {
        Http::fake(['drust.test/api/v1/docker/stacks' => Http::response(['success' => true, 'message' => 'Stack wp is up.', 'data' => ['name' => 'wp', 'warnings' => []]])]);
        $compose = "services:\n  db:\n    image: mysql:8.4\n    environment:\n      MYSQL_PASSWORD: \${DB_PASSWORD}\n";

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/stacks/action", ['action' => 'deploy_new', 'name' => 'wp', 'compose' => $compose, 'env' => 'DB_PASSWORD=hunter2'])
            ->assertOk()
            ->assertJsonPath('message', 'Stack wp is up.');

        Http::assertSent(fn ($request) => $request['action'] === 'deploy_new' && $request['env'] === 'DB_PASSWORD=hunter2' && $request['compose'] === $compose);
        $log = ActivityLog::query()->sole();
        $this->assertSame('docker.stack.deploy_new', $log->action);
        $this->assertStringNotContainsString('hunter2', json_encode($log->properties));
    }

    public function test_stack_names_and_services_are_validated(): void
    {
        Http::fake();

        $this->request()->postJson("/cpsess{$this->token}/docker/stacks/action", ['action' => 'up', 'name' => 'WordPress'])->assertStatus(422);
        $this->request()->postJson("/cpsess{$this->token}/docker/stacks/action", ['action' => 'up', 'name' => '../etc'])->assertStatus(422);
        $this->request()->postJson("/cpsess{$this->token}/docker/stacks/action", ['action' => 'restart', 'name' => 'wp', 'service' => '--all'])->assertStatus(422);
        $this->request()->postJson("/cpsess{$this->token}/docker/stacks/action", ['action' => 'create', 'name' => 'wp'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_system_clean_up_is_logged(): void
    {
        Http::fake(['drust.test/api/v1/docker/system' => Http::response(['success' => true, 'message' => 'Clean-up finished; 1.2GB freed.', 'data' => ['disk' => []]])]);

        $this->request()->postJson("/cpsess{$this->token}/docker/system/action", ['action' => 'prune', 'all' => false])->assertOk();
        $this->request()->postJson("/cpsess{$this->token}/docker/system/action", ['action' => 'volumes'])->assertStatus(422);

        $this->assertSame('docker.system.prune', ActivityLog::query()->sole()->action);
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
