<?php

namespace Tests\Feature;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Website;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\PathService;
use App\Services\ScriptExecutionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * The file tools build shell scripts, so these tests run them for real with
 * bash in a temp directory; only the drust gateway in between is faked.
 */
class McpFileToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $localConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Git for Windows keeps bash off PATH unless the shell is Git Bash.
        $bash = (new ExecutableFinder)->find('bash', null, ['C:/Program Files/Git/bin']);
        if ($bash === null) {
            $this->markTestSkipped('These tests run the file tools with bash, which this machine does not have.');
        }

        // The tools answer with forward slashes and the long form of a path.
        $this->root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/mcp-files-'.uniqid();
        File::makeDirectory($this->root.'/site/storage/logs', 0755, true);
        File::makeDirectory($this->root.'/backups');

        $this->localConfig = $this->root.'/mcp-local.conf';
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=full\n");
        config(['mcp.local_config_path' => $this->localConfig, 'mcp.backup_root' => $this->root.'/backups']);

        $gateway = $this->mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('withTimeout')->andReturnSelf();
        $gateway->shouldReceive('execute')
            ->with('mcp-exec.sh', Mockery::type('array'))
            ->andReturnUsing(function (string $script, array $args) use ($bash) {
                $process = proc_open([$bash, '-c', $args[2]], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $code = proc_close($process);

                return ['success' => true, 'ran' => true, 'exit_code' => 0, 'output' => rtrim($output, "\n")."\n__DPANEL_MCP_EXIT__={$code}", 'api_url' => '', 'script_path' => ''];
            });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_list_read_and_search(): void
    {
        [$plain] = $this->issueToken('read');
        file_put_contents($this->root.'/site/index.php', "<?php\necho 'hello';\nfunction broken() {}\n");
        file_put_contents($this->root.'/site/.htaccess', "RewriteEngine On\n");

        $entries = $this->callTool($plain, 'list_directory', ['path' => $this->root.'/site'])
            ->assertJsonPath('result.isError', false)
            ->json('result.structuredContent.entries');
        $this->assertSame([$this->root.'/site/.htaccess', $this->root.'/site/index.php', $this->root.'/site/storage'], array_column($entries, 'path'));
        $this->assertSame('d', $entries[2]['type']);

        $this->callTool($plain, 'list_directory', ['path' => $this->root.'/site', 'show_hidden' => false])
            ->assertJsonCount(2, 'result.structuredContent.entries');

        $this->callTool($plain, 'read_file', ['path' => $this->root.'/site/index.php', 'offset' => 2, 'limit' => 1])
            ->assertJsonPath('result.structuredContent.content', "echo 'hello';\n")
            ->assertJsonPath('result.structuredContent.total_lines', 3)
            ->assertJsonPath('result.structuredContent.more', true)
            ->assertJsonPath('result.structuredContent.sha256', hash_file('sha256', $this->root.'/site/index.php'));

        $this->callTool($plain, 'search_files', ['path' => $this->root.'/site', 'pattern' => 'function broken'])
            ->assertJsonPath('result.structuredContent.matches.0', $this->root.'/site/index.php:3:function broken() {}');

        $this->callTool($plain, 'search_files', ['path' => $this->root, 'name' => '*.php'])
            ->assertJsonPath('result.structuredContent.count', 1);
    }

    public function test_read_only_token_cannot_read_secrets_or_write(): void
    {
        [$plain] = $this->issueToken('read');
        file_put_contents($this->root.'/site/.env', "DB_PASSWORD=secret\n");
        $this->link($this->root.'/site/.env', $this->root.'/site/config.txt');

        $this->callTool($plain, 'read_file', ['path' => $this->root.'/site/.env'])->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'read_file', ['path' => $this->root.'/site/config.txt'])->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'search_files', ['path' => $this->root.'/site', 'pattern' => 'DB_PASSWORD'])
            ->assertJsonPath('result.structuredContent.count', 0);
        $this->callTool($plain, 'write_file', ['path' => $this->root.'/site/x.txt', 'content' => 'x'])->assertJsonPath('result.isError', true);

        $this->assertFileDoesNotExist($this->root.'/site/x.txt');
    }

    public function test_write_creates_and_replaces_with_backup(): void
    {
        [$plain] = $this->issueToken('full');
        $path = $this->root.'/site/config/app.ini';

        $this->callTool($plain, 'write_file', ['path' => $path, 'content' => 'a=1'])->assertJsonPath('result.isError', true);

        $this->callTool($plain, 'write_file', ['path' => $path, 'content' => "a=1\n", 'create_dirs' => true])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.created', true);
        chmod($path, 0640);

        // Large enough to need several write commands.
        $big = str_repeat("line with mkfs reboot passwd words ☃\n", 5000);
        $backup = $this->callTool($plain, 'write_file', ['path' => $path, 'content' => $big])
            ->assertJsonPath('result.structuredContent.created', false)
            ->json('result.structuredContent.backup_path');

        $this->assertSame($big, file_get_contents($path));
        $this->assertMode(0640, $path);
        $this->assertSame("a=1\n", file_get_contents($backup));
        $this->assertSame([], glob($this->root.'/site/config/.dpanel-mcp-*'));

        $this->callTool($plain, 'write_file', ['path' => $path, 'content' => 'x', 'expected_sha256' => hash('sha256', 'stale')])
            ->assertJsonPath('result.isError', true);
        $this->assertSame($big, file_get_contents($path));
    }

    public function test_edit_replaces_exact_text(): void
    {
        [$plain] = $this->issueToken('full');
        $path = $this->root.'/site/index.php';
        file_put_contents($path, "<?php\n\$debug = true;\n\$debug = true;\n");

        $this->callTool($plain, 'edit_file', ['path' => $path, 'old_string' => '$debug = true;', 'new_string' => '$debug = false;'])
            ->assertJsonPath('result.isError', true);

        $this->callTool($plain, 'edit_file', ['path' => $path, 'old_string' => '$debug = true;', 'new_string' => '$debug = false;', 'replace_all' => true])
            ->assertJsonPath('result.structuredContent.replacements', 2)
            ->assertJsonPath('result.structuredContent.first_line', 2);

        $this->assertSame("<?php\n\$debug = false;\n\$debug = false;\n", file_get_contents($path));
    }

    public function test_file_operations_and_delete_goes_to_trash(): void
    {
        [$plain] = $this->issueToken('full');
        $dir = $this->root.'/site/uploads';

        $this->callTool($plain, 'file_operation', ['action' => 'mkdir', 'path' => $dir])->assertJsonPath('result.isError', false);
        file_put_contents($dir.'/a.txt', 'a');

        $this->callTool($plain, 'file_operation', ['action' => 'copy', 'path' => $dir.'/a.txt', 'destination' => $dir.'/b.txt']);
        $this->callTool($plain, 'file_operation', ['action' => 'move', 'path' => $dir.'/b.txt', 'destination' => $dir.'/a.txt'])
            ->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'file_operation', ['action' => 'chmod', 'path' => $dir.'/b.txt', 'mode' => '600']);
        $this->assertMode(0600, $dir.'/b.txt');

        $this->callTool($plain, 'file_operation', ['action' => 'delete', 'path' => $dir])->assertJsonPath('result.isError', true);
        $trashed = $this->callTool($plain, 'file_operation', ['action' => 'delete', 'path' => $dir, 'recursive' => true])
            ->json('result.structuredContent.trashed_to');

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileExists($trashed.'/a.txt');

        $this->callTool($plain, 'file_operation', ['action' => 'delete', 'path' => '/etc'])->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'write_file', ['path' => '/etc/shadow', 'content' => 'x'])->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'read_file', ['path' => $this->root.'/site/../x'])->assertJsonPath('result.isError', true);
    }

    public function test_find_website_errors_reports_recent_log_errors(): void
    {
        [$plain] = $this->issueToken('read');
        file_put_contents($this->root.'/site/storage/logs/laravel.log', "[info] booted\n[error] SQLSTATE[HY000] Connection refused\n");
        Website::query()->create(['id' => 'site-1', 'domain' => 'example.test', 'status' => 'active', 'type' => 'primary', 'php_version' => '8.3', 'root_path' => $this->root.'/site']);

        $report = $this->callTool($plain, 'find_website_errors', ['domain' => 'example.test'])
            ->assertJsonPath('result.isError', false)
            ->json('result.structuredContent.report');

        $this->assertStringContainsString('=== '.$this->root.'/site/storage/logs/laravel.log', $report);
        $this->assertStringContainsString('Connection refused', $report);
        $this->assertStringNotContainsString('booted', $report);
    }

    /**
     * @return array<string, array{string, bool}> The path, and whether only Windows refuses it.
     */
    public static function windowsPathTricks(): array
    {
        return [
            'parent segment' => ['{root}/site/../x', false],
            'parent with backslash' => ['{root}\\site\\..\\site\\index.php', true],
            'data stream' => ['{root}/site/index.php::$DATA', true],
            'named stream' => ['{root}/site/index.php:hidden', true],
            'trailing dot' => ['{root}/site/index.php.', true],
            'trailing dots on a folder' => ['{root}/site../index.php', true],
            'space before a separator' => ['{root}/site /index.php', true],
            '8.3 name' => ['{root}/site/INDEX~1.PHP', true],
            'wildcard' => ['{root}/site/index.p?p', true],
            'device name' => ['{root}/site/NUL', true],
            'device name with extension' => ['{root}/site/com1.txt', true],
            'UNC' => ['\\\\server\\share\\index.php', false],
            'UNC with slashes' => ['//server/share/index.php', false],
            'extended path' => ['\\\\?\\C:\\Windows\\win.ini', false],
            'device path' => ['\\\\.\\C:\\Windows\\win.ini', false],
            'drive relative' => ['C:Windows/win.ini', false],
            'bare drive' => ['C:', false],
        ];
    }

    #[DataProvider('windowsPathTricks')]
    public function test_paths_that_windows_reads_as_another_file_are_refused(string $path, bool $windowsOnly): void
    {
        if ($windowsOnly && ! PathService::hostUsesDrivePaths()) {
            $this->markTestSkipped('On POSIX this is an ordinary file name.');
        }

        [$plain] = $this->issueToken('full');
        file_put_contents($this->root.'/site/index.php', 'secret');
        $path = str_replace('{root}', $this->root, $path);

        $this->callTool($plain, 'read_file', ['path' => $path])->assertJsonPath('result.isError', true);
        $this->callTool($plain, 'write_file', ['path' => $path, 'content' => 'changed'])->assertJsonPath('result.isError', true);

        $this->assertSame('secret', file_get_contents($this->root.'/site/index.php'));
    }

    public function test_guards_ignore_case_on_a_case_insensitive_disk(): void
    {
        [$read] = $this->issueToken('read');
        [$full] = $this->issueToken('full');
        file_put_contents($this->root.'/site/.env', "DB_PASSWORD=secret\n");
        File::ensureDirectoryExists($this->root.'/backups/kept');

        $this->callTool($read, 'read_file', ['path' => $this->root.'/site/.ENV'])->assertJsonPath('result.isError', true);
        $this->callTool($full, 'write_file', ['path' => $this->root.'/BACKUPS/kept/x', 'content' => 'x'])->assertJsonPath('result.isError', true);

        $this->assertFileDoesNotExist($this->root.'/backups/kept/x');
    }

    public function test_links_to_protected_folders_are_followed_by_the_guard(): void
    {
        [$full] = $this->issueToken('full');
        $this->link($this->root.'/backups', $this->root.'/site/kept');

        $this->callTool($full, 'write_file', ['path' => $this->root.'/site/kept/x', 'content' => 'x'])->assertJsonPath('result.isError', true);

        $this->assertFileDoesNotExist($this->root.'/backups/x');
    }

    public function test_a_path_is_never_run_as_shell(): void
    {
        [$plain] = $this->issueToken('full');
        $path = $this->root.'/site/a$(touch injected)`touch injected`b.txt';

        $this->callTool($plain, 'write_file', ['path' => $path, 'content' => 'x'])->assertJsonPath('result.isError', false);
        $this->callTool($plain, 'read_file', ['path' => $path])->assertJsonPath('result.structuredContent.content', 'x');

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($this->root.'/injected');
    }

    public function test_a_command_bash_would_cut_short_on_windows_is_refused(): void
    {
        if (! PathService::hostUsesDrivePaths()) {
            $this->markTestSkipped('The command length limit only applies on a Windows host.');
        }

        [$plain] = $this->issueToken('full');
        $marker = $this->root.'/ran';
        $command = "touch '{$marker}'\necho ".str_repeat('a', McpCommandExecutor::WINDOWS_MAX_COMMAND_CHARS);

        $response = $this->callTool($plain, 'run_command', ['command' => $command, 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);

        $this->assertStringContainsString('too long', (string) $response->json('result.structuredContent.error'));
        $this->assertFileDoesNotExist($marker);
    }

    public function test_arguments_are_quoted_for_bash_on_every_os(): void
    {
        $this->assertSame("'100%!'", McpCommandExecutor::quote('100%!'));
        $this->assertSame("'\$(id)\"'", McpCommandExecutor::quote('$(id)"'));
        $this->assertSame("'it'\\''s'", McpCommandExecutor::quote("it's"));
    }

    /** Windows files have no POSIX mode, so there it cannot be kept or changed. */
    private function assertMode(int $mode, string $path): void
    {
        if (! PathService::hostUsesDrivePaths()) {
            $this->assertSame($mode, fileperms($path) & 0777);
        }
    }

    /**
     * PHP's symlink() does not use the Developer Mode right that lets Windows
     * accounts create links without admin; mklink does.
     */
    private function link(string $target, string $link): void
    {
        if (! @symlink($target, $link)) {
            $flag = is_dir($target) ? '/D ' : '';
            exec('cmd /c mklink '.$flag.'"'.str_replace('/', '\\', $link).'" "'.str_replace('/', '\\', $target).'" 2>NUL');
        }

        if (! is_link($link)) {
            $this->markTestSkipped('This account may not create symlinks.');
        }
    }

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

        return [$generated['plain'], $token];
    }

    private function callTool(string $plain, string $name, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }
}
