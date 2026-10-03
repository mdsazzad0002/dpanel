<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class Fail2banControllerTest extends TestCase
{
    private string $token;

    private User $admin;

    private array $status = [
        'installed' => true,
        'running' => true,
        'jails' => [['name' => 'sshd', 'currently_banned' => 1, 'banned_ips' => ['203.0.113.7']]],
        'whitelist' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('f', 64);
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
        foreach (['' => 'SshLogins', '/whitelist' => 'Whitelist', '/blocklist' => 'Blocklist'] as $path => $component) {
            $this->request()
                ->get("/cpsess{$this->token}/security/fail2ban{$path}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component("Security/Fail2ban/{$component}"));
        }
    }

    public function test_status_is_read_from_drust(): void
    {
        Http::fake(['drust.test/api/v1/fail2ban' => Http::response(['success' => true, 'data' => $this->status])]);

        $this->request()
            ->getJson("/cpsess{$this->token}/security/fail2ban/status")
            ->assertOk()
            ->assertJsonPath('data.jails.0.banned_ips.0', '203.0.113.7');
    }

    public function test_unban_is_sent_to_drust_and_logged(): void
    {
        Http::fake(['drust.test/api/v1/fail2ban' => Http::response(['success' => true, 'data' => $this->status])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/security/fail2ban/unban", ['ip' => '203.0.113.7'])
            ->assertOk()
            ->assertJsonPath('message', '203.0.113.7 unblocked.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['action'] === 'unban'
            && $request['ip'] === '203.0.113.7'
            && $request->hasHeader('Authorization', 'Bearer secret'));
        $this->assertSame('fail2ban.unban', ActivityLog::query()->sole()->action);
    }

    public function test_whitelist_remove_uses_its_own_action(): void
    {
        Http::fake(['drust.test/api/v1/fail2ban' => Http::response(['success' => true, 'data' => $this->status])]);

        $this->request()
            ->deleteJson("/cpsess{$this->token}/security/fail2ban/whitelist", ['ip' => '198.51.100.4'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['action'] === 'whitelist_remove' && $request['ip'] === '198.51.100.4');
    }

    public function test_drust_errors_are_shown_without_the_failed_prefix(): void
    {
        Http::fake(['drust.test/api/v1/fail2ban' => Http::response(['success' => false, 'message' => "Failed: '0.0.0.0/0' is too wide"])]);

        $this->request()
            ->postJson("/cpsess{$this->token}/security/fail2ban/whitelist", ['ip' => '0.0.0.0/0'])
            ->assertStatus(422)
            ->assertJsonPath('message', "'0.0.0.0/0' is too wide");
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_ip_is_required(): void
    {
        Http::fake();

        $this->request()
            ->postJson("/cpsess{$this->token}/security/fail2ban/unban", [])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
