<?php

namespace Tests\Feature;

use App\Models\CommandJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerPanel\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_failed_command_dispatches_ai_analyzer(): void
    {
        $admin = $this->adminUser();
        $server = $this->server();

        $this->markTestSkipped('Command terminal has been removed.');
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
