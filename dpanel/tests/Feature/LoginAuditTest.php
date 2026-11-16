<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\LoginAudit\LoginLocationSetting;
use App\Services\LoginAudit\SshLoginImporter;
use App\Services\Security\DrustSecurityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LoginAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_password_and_unknown_email_are_recorded(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'nope'], ['REMOTE_ADDR' => '8.8.8.8']);
        $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'nope'], ['REMOTE_ADDR' => '8.8.8.8']);

        $events = LoginEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['failed', 'failed'], $events->pluck('result')->all());
        $this->assertSame('Wrong password', $events[0]->reason);
        $this->assertSame($user->id, $events[0]->user_id);
        $this->assertSame('No panel account with this email', $events[1]->reason);
        $this->assertNull($events[1]->user_id);
        $this->assertSame('ghost@example.com', $events[1]->username);
        $this->assertSame('8.8.8.8', $events[1]->ip_address);
    }

    public function test_successful_login_and_logout_are_recorded(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130.0']);
        $this->assertAuthenticated();

        $login = LoginEvent::query()->sole();
        $this->assertSame('success', $login->result);
        $this->assertSame('panel', $login->channel);
        $this->assertSame('password', $login->method);
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('Mozilla/5.0 Firefox/130.0', $login->user_agent);
        // 127.0.0.1 in tests: never sent to the map.
        $this->assertSame('private', $login->geo_status);

        auth()->logout();
        $this->assertSame('logout', LoginEvent::query()->latest('id')->first()->result);
    }

    public function test_https_login_requires_device_location(): void
    {
        app(LoginLocationSetting::class)->set('https', 'required');
        $user = User::factory()->create();

        $this->postJson('https://localhost/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_latitude');
        $this->assertGuest();
        $this->assertSame('Location not shared', LoginEvent::query()->sole()->reason);

        $this->postJson('https://localhost/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_latitude' => 23.810331,
            'device_longitude' => 90.412521,
            'device_accuracy' => 18.4,
        ])->assertOk();
        $this->assertAuthenticated();

        $login = LoginEvent::query()->where('result', 'success')->sole();
        $this->assertSame(23.810331, $login->device_latitude);
        $this->assertSame(90.412521, $login->device_longitude);
        $this->assertSame(18, $login->device_accuracy);
    }

    public function test_ask_mode_lets_https_login_through_without_location(): void
    {
        $this->assertSame('ask', app(LoginLocationSetting::class)->mode());
        $user = User::factory()->create();

        $this->postJson('https://localhost/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->assertAuthenticated();
    }

    public function test_admin_sets_each_protocol_on_its_own(): void
    {
        $token = str_repeat('b', 64);
        URL::defaults(['token' => $token]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $post = fn (string $scheme, array $body) => $this->withoutMiddleware()->actingAs($admin)
            ->withSession(['panel_session_token' => $token])
            ->postJson("{$scheme}://localhost/dpt{$token}/security/login-audit/location", $body);

        // Required works without HTTPS and without a test; the page warns instead.
        $post('http', ['scheme' => 'https', 'mode' => 'required'])->assertOk()->assertJsonPath('modes.https', 'required');
        $post('http', ['scheme' => 'http', 'mode' => 'off'])->assertOk()->assertJsonPath('modes.http', 'off');
        $post('http', ['scheme' => 'http', 'mode' => 'nonsense'])->assertStatus(422);

        $this->assertSame(['https' => 'required', 'http' => 'off'], app(LoginLocationSetting::class)->all());

        $this->artisan('login-audit:location', ['mode' => 'off'])->assertSuccessful();
        $this->assertSame(['https' => 'off', 'http' => 'off'], app(LoginLocationSetting::class)->all());
    }

    public function test_http_rule_applies_to_http_logins(): void
    {
        app(LoginLocationSetting::class)->set('http', 'required');
        $user = User::factory()->create();

        $this->postJson('http://localhost/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_latitude');
        // HTTPS kept its own rule (ask).
        $this->postJson('https://localhost/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_http_login_works_without_device_location(): void
    {
        $user = User::factory()->create();

        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticated();
        $this->assertNull(LoginEvent::query()->sole()->device_latitude);
    }

    public function test_ssh_events_are_imported_once(): void
    {
        $this->app->instance(DrustSecurityClient::class, new class extends DrustSecurityClient
        {
            public function __construct() {}

            public function sshHistory(): array
            {
                return ['events' => [
                    ['time' => 1_700_000_000, 'result' => 'accepted', 'method' => 'publickey', 'user' => 'root', 'ip' => '192.0.2.10'],
                    ['time' => 1_700_000_100, 'result' => 'failed', 'method' => 'password (invalid user)', 'user' => 'admin', 'ip' => '192.0.2.11'],
                ]];
            }
        });
        $importer = $this->app->make(SshLoginImporter::class);
        if (! $importer->supported()) {
            $this->markTestSkipped('SSH import needs Linux tools.');
        }

        $this->assertSame(2, $importer->import());
        $this->assertSame(0, $importer->import());
        $failed = LoginEvent::query()->where('result', 'failed')->sole();
        $this->assertSame('ssh', $failed->channel);
        $this->assertSame('No such Linux account', $failed->reason);
    }

    public function test_admin_can_list_events_and_map_points(): void
    {
        $token = str_repeat('a', 64);
        URL::defaults(['token' => $token]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        LoginEvent::query()->create([
            'channel' => 'panel', 'result' => 'failed', 'ip_address' => '203.0.113.5', 'username' => 'x@example.com',
            'geo_status' => 'located', 'country_code' => 'BD', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'latitude' => 23.78, 'longitude' => 90.39, 'occurred_at' => now(),
        ]);
        LoginEvent::query()->create([
            'channel' => 'ssh', 'result' => 'success', 'ip_address' => '203.0.113.5', 'username' => 'root',
            'geo_status' => 'located', 'country_code' => 'BD', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'latitude' => 23.78, 'longitude' => 90.39, 'occurred_at' => now(),
        ]);

        $request = $this->withoutMiddleware()->actingAs($admin)->withSession(['panel_session_token' => $token]);

        $request->getJson("/dpt{$token}/security/login-audit/events?channel=ssh")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'root');

        $request->getJson("/dpt{$token}/security/login-audit/events")
            ->assertOk()
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonPath('stats.countries.0.country_code', 'BD');

        $request->getJson("/dpt{$token}/security/login-audit/map")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.failed', 1);
    }
}
