<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FirewallRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('f', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function asAdmin(): static
    {
        return $this->withoutMiddleware()->actingAs($this->admin)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_it_lists_rules_and_status_from_drust(): void
    {
        Http::fake(['drust.test/firewall/rules' => Http::response([
            'success' => true,
            'status' => ['backend' => 'ufw', 'active' => true],
            'rules' => [['id' => 'aaaaaaaaaaaa', 'action' => 'allow', 'port' => ['from' => 443, 'to' => null, 'protocol' => 'tcp'], 'source' => null]],
        ])]);

        $this->asAdmin()->getJson("/dpt{$this->token}/security/firewall/rules/list")
            ->assertOk()
            ->assertJsonPath('status.backend', 'ufw')
            ->assertJsonPath('rules.0.port.from', 443);
    }

    public function test_adding_a_rule_sends_drusts_shape_and_hands_back_the_confirm_token(): void
    {
        Http::fake(['drust.test/firewall/rules' => Http::response([
            'success' => true, 'message' => 'Firewall rule added.', 'id' => 'bbbbbbbbbbbb',
            'confirm_token' => str_repeat('c', 24), 'confirm_within_seconds' => 60,
        ])]);

        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/rules", [
            'action' => 'deny', 'port_from' => 22, 'protocol' => 'tcp', 'source' => '198.51.100.0/24',
        ])->assertOk()->assertJsonPath('confirm_token', str_repeat('c', 24))->assertJsonPath('confirm_within_seconds', 60);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['action'] === 'deny'
            && $request['port'] === ['from' => 22, 'to' => null, 'protocol' => 'tcp']
            && $request['source'] === '198.51.100.0/24');
    }

    public function test_a_rule_needs_a_port_or_a_source_and_a_real_address(): void
    {
        Http::fake();

        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/rules", ['action' => 'deny'])
            ->assertJsonValidationErrors(['port_from', 'source']);
        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/rules", ['action' => 'deny', 'source' => '1.2.3.4; rm -rf /'])
            ->assertJsonValidationErrors(['source']);
        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/rules", ['action' => 'deny', 'source' => '10.0.0.0/33'])
            ->assertJsonValidationErrors(['source']);
        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/rules", ['action' => 'allow', 'port_from' => 9000, 'port_to' => 8000])
            ->assertJsonValidationErrors(['port_to']);

        Http::assertNothingSent();
    }

    public function test_removing_takes_only_a_rule_id(): void
    {
        Http::fake(['drust.test/firewall/rules/*' => Http::response(['success' => true, 'message' => 'Firewall rule removed.', 'id' => 'abcdef012345', 'confirm_token' => str_repeat('d', 24), 'confirm_within_seconds' => 60])]);

        $this->asAdmin()->deleteJson("/dpt{$this->token}/security/firewall/rules/abcdef012345")
            ->assertOk()->assertJsonPath('confirm_token', str_repeat('d', 24));
        $this->asAdmin()->deleteJson("/dpt{$this->token}/security/firewall/rules/..%2Fetc")->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_a_confirm_drust_no_longer_waits_for_says_the_change_was_undone(): void
    {
        Http::fake(['drust.test/firewall/confirm' => Http::response(['success' => false, 'message' => 'Nothing waits'], 404)]);

        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/confirm", ['token' => str_repeat('e', 24)])
            ->assertStatus(409)->assertJson(['ok' => false]);
    }

    public function test_a_kept_change_is_confirmed(): void
    {
        Http::fake(['drust.test/firewall/confirm' => Http::response(['success' => true, 'message' => 'Firewall change confirmed.'])]);

        $this->asAdmin()->postJson("/dpt{$this->token}/security/firewall/confirm", ['token' => str_repeat('e', 24)])
            ->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn ($request) => $request['token'] === str_repeat('e', 24));
    }

    public function test_every_rule_route_is_admin_only(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'security.firewall.rules') || $route->getName() === 'security.firewall.confirm');

        $this->assertCount(5, $routes);
        foreach ($routes as $route) {
            $this->assertContains('role:admin', $route->gatherMiddleware(), $route->getName().' must be admin-only');
        }
    }
}
