<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCommandErrorJob;
use App\Jobs\ExecuteSshCommandJob;
use App\Models\CommandJob;
use App\Models\Server;
use App\Models\SshCommandMemory;
use App\Models\User;
use App\Services\ServerPanel\CommandRunnerService;
use App\Services\ServerPanel\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServerPanelModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_create_stores_encrypted_password(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('servers.store'), [
            'name' => 'Production',
            'host' => '192.168.0.10',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'password',
            'password' => 'Secret123!',
            'mode' => 'setup',
        ])->assertRedirect();

        $cipherText = DB::table('servers')->value('encrypted_password');

        $this->assertNotNull($cipherText);
        $this->assertNotSame('Secret123!', $cipherText);
    }

    public function test_password_not_returned_in_response(): void
    {
        $admin = $this->adminUser();

        $server = Server::query()->create([
            'name' => 'Hidden Secret Host',
            'host' => '10.0.0.5',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'password',
            'encrypted_password' => 'TopSecret!1',
            'mode' => 'setup',
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertDontSee('TopSecret!1');
        $response->assertDontSee('encrypted_password');
    }

    public function test_safe_command_auto_queues(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $server = $this->server();

        $this->actingAs($admin)->post(route('commands.store'), [
            'server_id' => $server->id,
            'command' => 'whoami',
        ])->assertRedirect();

        $job = CommandJob::query()->latest()->first();

        $this->assertSame('safe', $job->risk_level);
        $this->assertSame('queued', $job->status);
        Queue::assertPushed(ExecuteSshCommandJob::class);
    }

    public function test_risky_command_goes_pending_approval(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $server = $this->server();

        $this->actingAs($admin)->post(route('commands.store'), [
            'server_id' => $server->id,
            'command' => 'apt install -y nginx',
        ])->assertRedirect();

        $job = CommandJob::query()->latest()->first();

        $this->assertSame('approval_required', $job->risk_level);
        $this->assertSame('pending_approval', $job->status);
        Queue::assertNotPushed(ExecuteSshCommandJob::class);
    }

    public function test_blocked_command_is_blocked(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $server = $this->server();

        $this->actingAs($admin)->post(route('commands.store'), [
            'server_id' => $server->id,
            'command' => 'rm -rf /',
        ])->assertRedirect();

        $job = CommandJob::query()->latest()->first();

        $this->assertSame('blocked', $job->risk_level);
        $this->assertSame('blocked', $job->status);
        Queue::assertNotPushed(ExecuteSshCommandJob::class);
    }

    public function test_approve_command_dispatches_job(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $server = $this->server();

        $job = CommandJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'command' => 'apt install -y redis',
            'risk_level' => 'approval_required',
            'status' => 'pending_approval',
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('commands.approve', $job))->assertRedirect();

        $job->refresh();
        $this->assertSame('queued', $job->status);
        Queue::assertPushed(ExecuteSshCommandJob::class);
    }

    public function test_failed_command_dispatches_ai_analyzer(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $server = $this->server();

        $job = CommandJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'command' => 'composer install',
            'risk_level' => 'approval_required',
            'status' => 'running',
            'requested_by' => $admin->id,
        ]);

        app(CommandRunnerService::class)->markFinished($job, [
            'output' => '',
            'error_output' => 'Command failed with dependency conflict',
            'exit_code' => 2,
        ]);

        Queue::assertPushed(AnalyzeCommandErrorJob::class);
    }

    public function test_report_file_generated(): void
    {
        Storage::fake('local');

        $server = $this->server();

        $job = CommandJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'command' => 'whoami',
            'risk_level' => 'safe',
            'status' => 'success',
            'output' => 'www-data',
            'error_output' => '',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $reportPath = app(ReportService::class)->generate($job);

        Storage::disk('local')->assertExists($reportPath);
    }

    public function test_memory_search_works(): void
    {
        $admin = $this->adminUser();

        SshCommandMemory::query()->create([
            'title' => 'Laravel Log Reader',
            'command' => 'tail -n 100 /var/www/html/storage/logs/laravel.log',
            'category' => 'logs',
            'tags' => ['laravel'],
        ]);

        SshCommandMemory::query()->create([
            'title' => 'Disk Check',
            'command' => 'df -h',
            'category' => 'diagnostics',
            'tags' => ['disk'],
        ]);

        $this->actingAs($admin)
            ->get(route('ssh-memories.index', ['q' => 'laravel']))
            ->assertOk()
            ->assertSee('Laravel Log Reader')
            ->assertDontSee('Disk Check');
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'Test Host',
            'host' => '127.0.0.1',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'password',
            'encrypted_password' => 'Password123!',
            'mode' => 'setup',
            'status' => 'unknown',
        ]);
    }
}
