<?php

namespace Tests\Feature;

use App\Models\McpAccessToken;
use App\Models\Server;
use App\Models\User;
use App\Services\Mcp\LocalHostAccess;
use App\Services\Mcp\McpCommandPolicy;
use App\Services\ScriptExecutionGateway;
use App\Services\ServerPanel\SshClientService;
use App\Support\Platform\LocalCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The MCP command policy and tools on a panel host that is not Linux (D5).
 * The panel host runs commands through Git's bash on Windows; a managed
 * server is always reached over SSH and stays Linux.
 */
class McpHostPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $localConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->localConfig = sys_get_temp_dir().'/mcp-local-'.uniqid().'.conf';
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=full\n");
        config(['mcp.local_config_path' => $this->localConfig]);
    }

    protected function tearDown(): void
    {
        @unlink($this->localConfig);

        parent::tearDown();
    }

    private function onHost(string $family): void
    {
        $this->app->instance(LocalCommand::class, new LocalCommand($family));
    }

    /** @return array<string, array{string}> */
    public static function windowsReadOnlyAllowed(): array
    {
        return [
            'ls' => ['ls -la C:/sites'],
            'df' => ['df -h'],
            'du' => ['du -sh /c/sites'],
            'php' => ['php -v'],
            'piped' => ['ls C:/sites | grep example'],
            'whoami' => ['whoami'],
        ];
    }

    #[DataProvider('windowsReadOnlyAllowed')]
    public function test_windows_host_allows_portable_read_only_commands(string $command): void
    {
        $this->onHost('Windows');
        $decision = app(McpCommandPolicy::class)->evaluate($command, $this->token('read'), panelHost: true);

        $this->assertTrue($decision['allowed'], $decision['reason']);
    }

    /** @return array<string, array{string}> */
    public static function windowsReadOnlyDenied(): array
    {
        return [
            'systemctl' => ['systemctl status nginx'],
            'systemctl extra' => ['systemctl is-active drust'],
            'journalctl' => ['journalctl -u drust'],
            'hostnamectl' => ['hostnamectl'],
            'free' => ['free -m'],
            'lscpu' => ['lscpu'],
            'nproc' => ['nproc'],
            'uptime' => ['uptime'],
            'ps aux' => ['ps aux'],
            'linux config' => ['cat /etc/os-release'],
            'panel config dir' => ['ls C:\\ProgramData\\dPanel\\config'],
            'panel config bash path' => ['ls /c/ProgramData/dPanel/config'],
            'drust env' => ['ls D:/dpanel/DRUST.ENV'],
            'ssh keys backslash' => ['ls C:\\Users\\admin\\.ssh'],
            'registry hives' => ['ls C:/Windows/System32/config'],
            'credentials' => ['ls C:/Users/a/AppData/Roaming/Microsoft/Credentials'],
            'cmd chaining' => ['dir & del x'],
        ];
    }

    #[DataProvider('windowsReadOnlyDenied')]
    public function test_windows_host_denies_linux_only_and_sensitive_read_only_commands(string $command): void
    {
        $this->onHost('Windows');
        $decision = app(McpCommandPolicy::class)->evaluate($command, $this->token('read'), panelHost: true);

        $this->assertFalse($decision['allowed'], "{$command} should be refused");
    }

    /** @return array<string, array{string}> */
    public static function windowsBlocked(): array
    {
        return [
            'format' => ['format C: /q'],
            'format via cmd' => ['cmd.exe /c format d: /y'],
            'rm drive root' => ['rm -rf C:/'],
            'rm bash drive root' => ['rm -rf /c'],
            'rd drive root' => ['cmd /c rd /s /q C:\\'],
            'del drive root' => ['cmd //c "del /s /q D:\\*"'],
            'remove-item drive root' => ['powershell -c "Remove-Item -Recurse -Force C:\\"'],
            'diskpart' => ['diskpart /s wipe.txt'],
            'bcdedit' => ['bcdedit /set {default} safeboot minimal'],
            'shadow copies' => ['vssadmin delete shadows /all /quiet'],
            'shutdown' => ['shutdown /r /t 0'],
            'restart-computer' => ['powershell Restart-Computer -Force'],
            'user accounts' => ['net user Administrator NewPass1'],
            'registry delete' => ['reg delete HKLM\\Software\\dPanel /f'],
            'firewall off' => ['netsh advfirewall set allprofiles state off'],
            'acl on drive root' => ['icacls C:\\ /grant Everyone:F'],
        ];
    }

    #[DataProvider('windowsBlocked')]
    public function test_windows_host_blocks_destructive_commands_even_for_full_tokens(string $command): void
    {
        $this->onHost('Windows');
        $decision = app(McpCommandPolicy::class)->evaluate($command, $this->token('full'), panelHost: true);

        $this->assertFalse($decision['allowed'], "{$command} should be blocked");
        $this->assertSame('blocked', $decision['risk_level']);
    }

    public function test_windows_host_lets_full_tokens_change_things_below_a_drive_root(): void
    {
        $this->onHost('Windows');
        $policy = app(McpCommandPolicy::class);
        $full = $this->token('full');

        foreach (['rm -r C:/sites/example/cache', 'cmd /c rd /s /q C:\\sites\\example\\tmp', 'php artisan cache:clear', 'format-table'] as $command) {
            $this->assertTrue($policy->evaluate($command, $full, panelHost: true)['allowed'], $command);
        }
    }

    public function test_managed_servers_and_linux_hosts_keep_the_linux_rules(): void
    {
        $read = $this->token('read');

        foreach (['Windows', 'Linux'] as $family) {
            $this->onHost($family);
            $policy = app(McpCommandPolicy::class);

            foreach (['systemctl status nginx', 'journalctl -u drust', 'free -m', 'lscpu', 'uptime', 'ps aux', 'cat /etc/os-release'] as $command) {
                $this->assertTrue($policy->evaluate($command, $read, panelHost: $family === 'Linux')['allowed'], "{$family}: {$command}");
                $this->assertTrue($policy->evaluate($command, $read)['allowed'], "remote: {$command}");
            }

            // Windows-only rules never touch a Linux target.
            $this->assertTrue($policy->evaluate('ls /srv/dpanel/config', $read, panelHost: $family === 'Linux')['allowed']);
            $this->assertNotSame('blocked', $policy->evaluate('reg delete x', $this->token('full'))['risk_level']);
        }
    }

    public function test_read_only_command_list_matches_the_host(): void
    {
        $this->onHost('Windows');
        $policy = app(McpCommandPolicy::class);

        $this->assertContains('systemctl status', $policy->readOnlyCommands());
        $this->assertNotContains('systemctl status', $policy->readOnlyCommands(panelHost: true));
        $this->assertNotContains('free -m', $policy->readOnlyCommands(panelHost: true));
        $this->assertContains('df -h', $policy->readOnlyCommands(panelHost: true));
    }

    public function test_run_command_refuses_systemctl_on_a_windows_panel_host(): void
    {
        $this->onHost('Windows');
        [$plain] = $this->issueToken('read');
        $this->mock(ScriptExecutionGateway::class)->shouldNotReceive('execute');

        $response = $this->callTool($plain, 'run_command', ['command' => 'systemctl status nginx', 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);

        $this->assertStringContainsString('windows', $response->json('result.structuredContent.error'));
    }

    public function test_run_command_refuses_destructive_windows_command_on_the_panel_host(): void
    {
        $this->onHost('Windows');
        [$plain] = $this->issueToken('full');
        $this->mock(ScriptExecutionGateway::class)->shouldNotReceive('execute');

        $this->callTool($plain, 'run_command', ['command' => 'format C: /q', 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function systemdTools(): array
    {
        return [
            'status' => ['get_service_status', ['service' => 'drust']],
            'restart' => ['restart_service', ['service' => 'drust']],
            'logs' => ['read_service_logs', ['service' => 'drust']],
        ];
    }

    #[DataProvider('systemdTools')]
    public function test_systemd_tools_are_disabled_for_a_panel_host_without_systemd(string $tool, array $arguments): void
    {
        $this->onHost('Windows');
        [$plain] = $this->issueToken('full');
        $this->mock(ScriptExecutionGateway::class)->shouldNotReceive('execute');

        $response = $this->callTool($plain, $tool, [...$arguments, 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);

        $this->assertStringContainsString('systemd', $response->json('result.structuredContent.error'));
    }

    #[DataProvider('systemdTools')]
    public function test_systemd_tools_still_reach_a_managed_linux_server_from_windows(string $tool, array $arguments): void
    {
        $this->onHost('Windows');
        [$plain] = $this->issueToken('full');
        $server = $this->server();
        $this->mock(SshClientService::class)
            ->shouldReceive('executeOnServer')
            ->once()
            ->with(Mockery::type(Server::class), Mockery::pattern('/^(systemctl|journalctl) /'))
            ->andReturn(['output' => 'active', 'error_output' => '', 'exit_code' => 0]);

        $this->callTool($plain, $tool, [...$arguments, 'server_id' => $server->id])
            ->assertJsonPath('result.isError', false);
    }

    public function test_read_log_file_on_windows_reads_the_panel_log_folder(): void
    {
        $this->onHost('Windows');
        // The disk ignores case, so the root does too.
        config(['mcp.windows_log_roots' => ['c:/programdata/dpanel/logs/']]);
        [$plain] = $this->issueToken('read');

        $this->mock(ScriptExecutionGateway::class, function ($gateway) {
            $gateway->shouldReceive('withTimeout')->andReturnSelf();
            $gateway->shouldReceive('execute')
                ->once()
                ->with('mcp-exec.sh', ['read', '300', "tail -n 50 'C:/ProgramData/dPanel/logs/drust.log' 2>&1"])
                ->andReturn(['success' => true, 'ran' => true, 'exit_code' => 0, 'output' => "started\n__DPANEL_MCP_EXIT__=0", 'api_url' => '', 'script_path' => '']);
        });

        $this->callTool($plain, 'read_log_file', ['path' => 'C:\\ProgramData\\dPanel\\logs\\drust.log', 'lines' => 50, 'server_id' => 'local'])
            ->assertJsonPath('result.isError', false);
    }

    /** @return array<string, array{string}> */
    public static function windowsLogPathsRefused(): array
    {
        return [
            'linux path' => ['/var/log/syslog'],
            'outside root' => ['C:/Windows/System32/config/SAM'],
            'traversal' => ['C:/ProgramData/dPanel/logs/../config/drust.env'],
            'stream' => ['C:/ProgramData/dPanel/logs/a.log::$DATA'],
            'unc' => ['//server/share/logs/a.log'],
        ];
    }

    #[DataProvider('windowsLogPathsRefused')]
    public function test_read_log_file_on_windows_refuses_other_paths(string $path): void
    {
        $this->onHost('Windows');
        config(['mcp.windows_log_roots' => ['C:/ProgramData/dPanel/logs/']]);
        [$plain] = $this->issueToken('read');
        $this->mock(ScriptExecutionGateway::class)->shouldNotReceive('execute');

        $this->callTool($plain, 'read_log_file', ['path' => $path, 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_local_access_config_lives_in_the_os_config_folder(): void
    {
        config(['mcp.local_config_path' => null]);

        $this->assertSame('/etc/dpanel/mcp-local.conf', (new LocalHostAccess(new LocalCommand('Linux')))->configPath());
        $this->assertStringEndsWith('dPanel\\config\\mcp-local.conf', (new LocalHostAccess(new LocalCommand('Windows')))->configPath());
        $this->assertSame('/Library/dPanel/config/mcp-local.conf', (new LocalHostAccess(new LocalCommand('Darwin')))->configPath());

        $this->assertStringContainsString('sudo dpanel mcp local on', (new LocalHostAccess(new LocalCommand('Linux')))->enableHint());
        $this->assertStringContainsString('mcp-local.conf', (new LocalHostAccess(new LocalCommand('Windows')))->enableHint());
        $this->assertStringNotContainsString('sudo', (new LocalHostAccess(new LocalCommand('Windows')))->enableHint());
    }

    private function token(string $scope): McpAccessToken
    {
        return $this->issueToken($scope)[1];
    }

    /**
     * @return array{0: string, 1: McpAccessToken}
     */
    private function issueToken(string $scope): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $generated = McpAccessToken::generate();

        $token = McpAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => $scope,
        ]);

        return [$generated['plain'], $token->fresh('user')];
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'Linux Host',
            'host' => '127.0.0.1',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'password',
            'encrypted_password' => 'Password123!',
            'mode' => 'setup',
            'status' => 'online',
        ]);
    }

    private function callTool(string $plain, string $name, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }
}
