<?php

namespace Tests\Feature;

use App\Jobs\StartDockerSiteJob;
use App\Models\User;
use App\Models\Website;
use App\Services\EdgeGatewayReloader;
use App\Services\Website\DockerSiteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebsiteDockerTest extends TestCase
{
    private string $token;

    private User $admin;

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
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->string('scope')->default('user');
            $table->string('root_path')->nullable();
            $table->string('runtime')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->string('python_process_status')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_09_000000_add_docker_runtime_to_websites_table.php'))->up();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert([
            'id' => 'site-1',
            'domain' => 'app.test',
            'root_path' => '/home/appuser/public_html',
            'runtime' => 'docker',
            'docker_image' => 'nginx:alpine',
            'docker_port' => 50000,
            'docker_container_port' => 80,
            'docker_mount_target' => '/usr/share/nginx/html',
            'docker_process_status' => 'pending',
        ]);
        Website::query()->findOrFail('site-1')->forceFill(['docker_env' => [['key' => 'APP_KEY', 'value' => 's3cret']]])->saveQuietly();

        $this->mock(EdgeGatewayReloader::class)->shouldReceive('reloadDomains')->andReturn(true);
        config([
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
        ]);
    }

    private function fakeDrust(array $containers): void
    {
        Http::fake(function ($request) use ($containers) {
            $status = ['installed' => true, 'running' => true, 'containers' => $containers, 'images' => []];

            return Http::response(['success' => true, 'message' => 'ok', 'data' => $status]);
        });
    }

    public function test_recreate_replaces_the_container_with_a_private_port_and_the_site_folder(): void
    {
        $this->fakeDrust([['name' => 'dpanel-site-site-1', 'state' => 'running']]);

        $this->request()
            ->postJson("/cpsess{$this->token}/websites/site-1/docker/control", ['action' => 'recreate'])
            ->assertOk()
            ->assertJsonPath('docker_process_status', 'running');

        $actions = collect(Http::recorded())->map(fn ($pair) => $pair[0]['action'] ?? 'status')->values()->all();
        $this->assertSame(['status', 'remove', 'run', 'status'], $actions);
        Http::assertSent(fn ($request) => ($request['action'] ?? '') === 'run'
            && $request['spec']['name'] === 'dpanel-site-site-1'
            && $request['spec']['ports'][0] === ['host' => 50000, 'container' => 80, 'protocol' => 'tcp', 'public' => false]
            && $request['spec']['volumes'][0] === ['source' => '/home/appuser/public_html', 'target' => '/usr/share/nginx/html', 'read_only' => false]
            && $request['spec']['env'][0] === ['key' => 'APP_KEY', 'value' => 's3cret']);
    }

    public function test_stop_marks_the_site_stopped(): void
    {
        $this->fakeDrust([['name' => 'dpanel-site-site-1', 'state' => 'running']]);

        $this->request()
            ->postJson("/cpsess{$this->token}/websites/site-1/docker/control", ['action' => 'stop'])
            ->assertOk();

        Http::assertSent(fn ($request) => ($request['action'] ?? '') === 'stop' && $request['id'] === 'dpanel-site-site-1');
        $this->assertSame('stopped', Website::query()->findOrFail('site-1')->docker_process_status);
    }

    public function test_public_toggle_is_saved_and_applied_by_recreating(): void
    {
        Queue::fake();

        $this->request()
            ->patchJson("/cpsess{$this->token}/websites/site-1/docker/public", ['public' => true])
            ->assertOk()
            ->assertJsonPath('docker_public', true);

        $this->assertTrue(Website::query()->findOrFail('site-1')->docker_public);
        Queue::assertPushed(StartDockerSiteJob::class, fn ($job) => $job->websiteId === 'site-1');
    }

    public function test_only_a_folder_under_home_is_ever_mounted(): void
    {
        $website = Website::query()->findOrFail('site-1');
        $website->root_path = '/etc';

        $this->expectExceptionMessage('no folder under /home');
        app(DockerSiteService::class)->runSpec($website);
    }

    public function test_variable_values_stay_out_of_serialized_websites(): void
    {
        $website = Website::query()->findOrFail('site-1');

        $this->assertArrayNotHasKey('docker_env', $website->toArray());
        $this->assertStringNotContainsString('s3cret', (string) DB::table('websites')->value('docker_env'));
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
