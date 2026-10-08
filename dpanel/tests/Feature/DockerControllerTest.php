<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ActivityLog;
use App\Services\Docker\DrustDockerClient;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DockerControllerTest extends TestCase
{
    private string $token;

    private User $admin;

    private array $status = [
        'installed' => true,
        'running' => true,
        'version' => '27.3.1',
        'containers' => [['id' => '3f2a', 'name' => 'web', 'image' => 'nginx', 'state' => 'running']],
        'images' => [['id' => 'a1b2', 'repository' => 'nginx', 'tag' => 'alpine']],
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

    public function test_each_menu_entry_opens_its_own_page(): void
    {
        foreach (['' => 'Containers', '/images' => 'Images'] as $path => $component) {
            $this->request()
                ->get("/cpsess{$this->token}/docker{$path}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component("Docker/{$component}"));
        }
    }

    public function test_menu_flag_follows_whether_docker_is_installed(): void
    {
        foreach ([true, false] as $installed) {
            $this->mock(DrustDockerClient::class)->shouldReceive('installed')->andReturn($installed);
            $shared = app(HandleInertiaRequests::class)->share(Request::create('/'));

            $this->assertSame($installed, $shared['features']['docker']());
        }
    }

    public function test_status_is_read_from_drust(): void
    {
        Http::fake(['drust.test/api/v1/docker' => Http::response(['success' => true, 'data' => $this->status])]);

        $this->request()
            ->getJson("/cpsess{$this->token}/docker/status")
            ->assertOk()
            ->assertJsonPath('data.containers.0.name', 'web');
    }

    public function test_container_action_is_sent_to_drust_and_logged(): void
    {
        Http::fake(['drust.test/api/v1/docker' => Http::response(['success' => true, 'message' => 'Container web stopped.', 'data' => $this->status])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/action", ['action' => 'stop', 'id' => 'web'])
            ->assertOk()
            ->assertJsonPath('message', 'Container web stopped.');

        Http::assertSent(fn ($request) => $request['action'] === 'stop'
            && $request['id'] === 'web'
            && $request->hasHeader('Authorization', 'Bearer secret'));
        $this->assertSame('docker.stop', ActivityLog::query()->sole()->action);
    }

    public function test_run_fills_defaults_and_keeps_env_values_out_of_the_log(): void
    {
        Http::fake(['drust.test/api/v1/docker' => Http::response(['success' => true, 'message' => 'Container 3f2a is running nginx.', 'data' => $this->status])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/run", [
                'image' => 'nginx:alpine',
                'ports' => [['host' => 8080, 'container' => 80]],
                'env' => [['key' => 'DB_PASSWORD', 'value' => 'hunter2']],
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['action'] === 'run'
            && $request['spec']['ports'][0] === ['host' => 8080, 'container' => 80, 'protocol' => 'tcp', 'public' => false]
            && $request['spec']['env'][0]['value'] === 'hunter2'
            && $request['spec']['name'] === '');
        $log = ActivityLog::query()->sole();
        $this->assertSame('docker.run', $log->action);
        $this->assertSame(['DB_PASSWORD'], $log->properties['env']);
        $this->assertStringNotContainsString('hunter2', json_encode($log->properties));
    }

    public function test_option_like_values_never_reach_drust(): void
    {
        Http::fake();

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/action", ['action' => 'stop', 'id' => '--all'])
            ->assertStatus(422);
        $this->request()
            ->postJson("/cpsess{$this->token}/docker/images/pull", ['image' => 'nginx; rm -rf /'])
            ->assertStatus(422);
        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/action", ['action' => 'exec', 'id' => 'web'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_drust_errors_are_shown_without_the_failed_prefix(): void
    {
        Http::fake(['drust.test/api/v1/docker' => Http::response(['success' => false, 'message' => 'Failed: image is being used by running container 3f2a'])]);

        $this->request()
            ->deleteJson("/cpsess{$this->token}/docker/images", ['image' => 'nginx:alpine'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'image is being used by running container 3f2a');
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_logs_are_read_from_drust(): void
    {
        Http::fake(['drust.test/api/v1/docker/logs' => Http::response(['success' => true, 'data' => ['id' => 'web', 'lines' => 50, 'logs' => 'ready']])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/docker/containers/logs", ['id' => 'web', 'lines' => 50])
            ->assertOk()
            ->assertJsonPath('data.logs', 'ready');
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
