<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WafBanControllerTest extends TestCase
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

    public function test_it_lists_the_bans_from_drust(): void
    {
        Http::fake(['drust.test/firewall/waf-bans' => Http::response([
            'success' => true,
            'bans' => [['ip' => '203.0.113.7', 'until' => 1_800_000_000, 'strikes' => 2]],
        ])]);

        $this->asAdmin()->getJson("/dpt{$this->token}/security/firewall/waf-bans")
            ->assertOk()
            ->assertJsonPath('bans.0.ip', '203.0.113.7')
            ->assertJsonPath('bans.0.strikes', 2);
    }

    public function test_lifting_a_ban_asks_drust_and_logs_it(): void
    {
        Http::fake(['drust.test/firewall/waf-bans/*' => Http::response(['success' => true, 'message' => 'Ban lifted.'])]);

        $this->asAdmin()->deleteJson("/dpt{$this->token}/security/firewall/waf-bans/2001:db8::7")
            ->assertOk()
            ->assertJsonPath('message', 'Ban lifted.');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/firewall/waf-bans/2001%3Adb8%3A%3A7'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'firewall.waf_ban_lifted']);
    }

    public function test_an_address_that_is_not_one_never_reaches_drust(): void
    {
        Http::fake();

        $this->asAdmin()->deleteJson("/dpt{$this->token}/security/firewall/waf-bans/999.1.1.1")->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_drust_refusing_shows_its_message(): void
    {
        Http::fake(['drust.test/firewall/waf-bans/*' => Http::response(['success' => false, 'message' => 'This address is not banned.'], 404)]);

        $this->asAdmin()->deleteJson("/dpt{$this->token}/security/firewall/waf-bans/203.0.113.7")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This address is not banned.');
    }
}
