<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\McpAccessGroup;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpAccessGroupTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_grouped_token_only_lists_its_tools(): void
    {
        $group = $this->group(['get_panel_overview', 'list_websites']);
        [$plain] = $this->issueToken($group);

        $names = collect($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['get_panel_overview', 'list_websites'], $names);
    }

    public function test_grouped_token_cannot_call_tools_outside_its_group(): void
    {
        $group = $this->group(['list_websites']);
        [$plain] = $this->issueToken($group, 'full');

        $this->mcp($plain, 'tools/call', ['name' => 'run_command', 'arguments' => ['command' => 'df -h']])
            ->assertOk()
            ->assertJsonPath('error.code', -32602);

        $this->mcp($plain, 'tools/call', ['name' => 'list_websites', 'arguments' => new \stdClass])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }

    public function test_token_without_group_keeps_every_tool(): void
    {
        [$plain] = $this->issueToken();

        $names = collect($this->mcp($plain, 'tools/list')->json('result.tools'))->pluck('name');

        $this->assertContains('run_command', $names);
        $this->assertContains('write_file', $names);
    }

    public function test_admin_manages_groups_and_cannot_delete_one_in_use(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $id = $this->asAdmin($admin)->postJson(route('mcp.groups.store', ['token' => self::SESSION]), [
            'name' => 'Monitoring',
            'tools' => ['get_panel_overview', 'run_health_check'],
        ])->assertOk()->json('group.id');

        $this->asAdmin($admin)->postJson(route('mcp.groups.store', ['token' => self::SESSION]), [
            'name' => 'Bad',
            'tools' => ['not_a_tool'],
        ])->assertUnprocessable();

        $this->asAdmin($admin)->putJson(route('mcp.groups.update', ['token' => self::SESSION, 'mcpGroup' => $id]), [
            'name' => 'Monitoring',
            'tools' => ['get_panel_overview'],
        ])->assertOk()->assertJsonPath('group.tools', ['get_panel_overview']);

        $this->asAdmin($admin)->postJson(route('mcp.tokens.store', ['token' => self::SESSION]), [
            'name' => 'Grafana bot',
            'scope' => 'read',
            'access_group_id' => $id,
        ])->assertOk()->assertJsonPath('token.access_group.id', $id);

        $this->asAdmin($admin)->deleteJson(route('mcp.groups.destroy', ['token' => self::SESSION, 'mcpGroup' => $id]))
            ->assertStatus(422);

        McpAccessToken::query()->delete();

        $this->asAdmin($admin)->deleteJson(route('mcp.groups.destroy', ['token' => self::SESSION, 'mcpGroup' => $id]))
            ->assertOk();
        $this->assertDatabaseCount('mcp_access_groups', 0);
    }

    public function test_non_admin_cannot_create_groups(): void
    {
        $user = User::factory()->create(['role' => 'general']);

        $this->asAdmin($user)->postJson(route('mcp.groups.store', ['token' => self::SESSION]), [
            'name' => 'x',
            'tools' => ['list_websites'],
        ])->assertForbidden();
    }

    private function asAdmin(User $user): static
    {
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->withSession(['panel_session_token' => self::SESSION])
            ->actingAs($user);
    }

    private function group(array $tools): McpAccessGroup
    {
        return McpAccessGroup::query()->create(['name' => 'test group', 'tools' => $tools]);
    }

    /**
     * @return array{0: string, 1: McpAccessToken}
     */
    private function issueToken(?McpAccessGroup $group = null, string $scope = 'read'): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $generated = McpAccessToken::generate();

        $token = McpAccessToken::query()->create([
            'user_id' => $user->id,
            'access_group_id' => $group?->id,
            'name' => 'test',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => $scope,
        ]);

        return [$generated['plain'], $token];
    }

    private function mcp(string $plain, string $method, mixed $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params,
        ]);
    }
}
