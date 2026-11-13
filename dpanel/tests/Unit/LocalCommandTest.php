<?php

namespace Tests\Unit;

use App\Support\Platform\LocalCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalCommandTest extends TestCase
{
    public function test_the_os_is_named_as_drust_names_it(): void
    {
        $this->assertSame('linux', (new LocalCommand('Linux'))->os());
        $this->assertSame('windows', (new LocalCommand('Windows'))->os());
        $this->assertSame('macos', (new LocalCommand('Darwin'))->os());
        $this->assertSame('other', (new LocalCommand('BSD'))->os());
        $this->assertSame(match (PHP_OS_FAMILY) {
            'Linux' => 'linux', 'Windows' => 'windows', 'Darwin' => 'macos', default => 'other',
        }, (new LocalCommand)->os());
    }

    /** @return array<string, array{string, array<string, bool>}> */
    public static function toolSets(): array
    {
        return [
            'linux' => ['Linux', [LocalCommand::UNIX_TOOLS => true, LocalCommand::LINUX_TOOLS => true, LocalCommand::SYSTEMD => true, LocalCommand::POSTFIX => true]],
            'macos' => ['Darwin', [LocalCommand::UNIX_TOOLS => true, LocalCommand::LINUX_TOOLS => false, LocalCommand::SYSTEMD => false, LocalCommand::POSTFIX => false]],
            'windows' => ['Windows', [LocalCommand::UNIX_TOOLS => false, LocalCommand::LINUX_TOOLS => false, LocalCommand::SYSTEMD => false, LocalCommand::POSTFIX => false]],
        ];
    }

    /** @param  array<string, bool>  $expected */
    #[DataProvider('toolSets')]
    public function test_each_os_has_its_own_tools(string $family, array $expected): void
    {
        $local = new LocalCommand($family);

        foreach ($expected as $tools => $supported) {
            $this->assertSame($supported, $local->supports($tools), "{$family} {$tools}");
        }
    }

    public function test_an_unknown_tool_set_is_a_mistake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LocalCommand('Linux'))->supports('telepathy');
    }

    public function test_run_passes_the_argv_without_a_shell(): void
    {
        Process::fake(['*' => Process::result(output: 'ok')]);

        $result = (new LocalCommand)->run(['systemctl', 'is-active', "a'b; rm -rf /"], cwd: '/tmp', timeout: 5, env: ['X' => '1'], input: 'in');

        $this->assertSame('ok', trim($result->output()));
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['systemctl', 'is-active', "a'b; rm -rf /"]
            && $process->path === '/tmp'
            && $process->timeout === 5
            && $process->environment === ['X' => '1']
            && $process->input === 'in');
    }

    /** @return array<string, array{array<mixed>}> */
    public static function badCommands(): array
    {
        return [
            'empty' => [[]],
            'a shell string in disguise' => [['program' => 'ls']],
            'no program' => [['', '-l']],
            'null byte' => [['ls', "a\0b"]],
            'not a string' => [['ls', 3]],
        ];
    }

    /** @param  array<mixed>  $argv */
    #[DataProvider('badCommands')]
    public function test_a_bad_command_is_refused_before_it_runs(array $argv): void
    {
        Process::fake();

        try {
            (new LocalCommand)->run($argv);
            $this->fail('The command ran.');
        } catch (InvalidArgumentException) {
            Process::assertNothingRan();
        }
    }

    public function test_output_is_stdout_whatever_the_exit_code_like_shell_exec(): void
    {
        Process::fake(fn (PendingProcess $process) => match ($process->command[0]) {
            'systemctl' => Process::result(output: 'inactive', errorOutput: 'noise', exitCode: 3),
            'silent' => Process::result(exitCode: 0),
            default => Process::result(errorOutput: 'not found', exitCode: 127),
        });
        $local = new LocalCommand;

        $this->assertSame("inactive\n", $local->output(['systemctl', 'is-active', 'ssh']));
        $this->assertNull($local->output(['silent']));
        $this->assertNull($local->output(['missing']));
        $this->assertSame("inactive\nnoise\n", $local->combinedOutput(['systemctl', 'is-active', 'ssh']));
        $this->assertSame("not found\n", $local->combinedOutput(['missing']));
        $this->assertFalse($local->succeeds(['missing']));
        $this->assertTrue($local->succeeds(['silent']));
    }

    public function test_a_program_that_cannot_start_gives_no_output(): void
    {
        $local = new LocalCommand;

        $this->assertNull($local->output(['dpanel-no-such-program-x']));
        $this->assertSame('', $local->combinedOutput([PHP_BINARY, '-r', 'exit(0);']));
        $this->assertFalse($local->succeeds(['dpanel-no-such-program-x']));
    }

    public function test_find_searches_path_on_unix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permissions.');
        }

        $directory = sys_get_temp_dir().'/dpanel-find-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory.'/bin');
        File::put($directory.'/bin/mariadb-dump', "#!/bin/sh\n");
        chmod($directory.'/bin/mariadb-dump', 0755);
        File::put($directory.'/bin/mysqldump', 'not executable');
        chmod($directory.'/bin/mysqldump', 0644);

        try {
            $local = $this->withPath('Linux', '/nowhere:'.$directory.'/bin');

            $this->assertSame($directory.'/bin/mariadb-dump', $local->find('mariadb-dump'));
            $this->assertNull($local->find('mysqldump'));
            $this->assertNull($local->find('pg_dump'));
            $this->assertSame($directory.'/bin/mariadb-dump', $local->find($directory.'/bin/mariadb-dump'));
            $this->assertNull($local->find(''));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_find_uses_pathext_and_semicolons_on_windows(): void
    {
        $local = new class('Windows') extends LocalCommand
        {
            protected function searchPath(): string
            {
                return 'C:\\Windows\\system32;"C:\\Program Files\\MariaDB 11\\bin";';
            }

            protected function isProgram(string $path): bool
            {
                return in_array($path, ['C:\\Program Files\\MariaDB 11\\bin\\mariadb-dump.exe', 'C:\\Windows\\system32\\where.exe'], true);
            }
        };

        $this->assertSame('C:\\Program Files\\MariaDB 11\\bin\\mariadb-dump.exe', $local->find('mariadb-dump'));
        $this->assertSame('C:\\Program Files\\MariaDB 11\\bin\\mariadb-dump.exe', $local->find('mariadb-dump.exe'));
        $this->assertSame('C:\\Windows\\system32\\where.exe', $local->find('where'));
        $this->assertNull($local->find('mysqldump'));
        $this->assertSame('C:\\Program Files\\MariaDB 11\\bin\\mariadb-dump.exe', $local->find('C:\\Program Files\\MariaDB 11\\bin\\mariadb-dump'));
    }

    public function test_cron_lines_use_the_system_php_cli_never_php_fpm(): void
    {
        $php = (new LocalCommand('Linux'))->panelPhpCli();

        $this->assertStringNotContainsString('fpm', basename($php));
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (is_executable("/usr/bin/php{$version}")) {
            // Root's cron must not depend on a user's own PHP (Herd, phpenv).
            $this->assertSame("/usr/bin/php{$version}", $php);
        }
    }

    public function test_the_linux_cron_line_is_byte_for_byte_the_old_one(): void
    {
        $local = new LocalCommand('Linux');

        $this->assertSame(
            'cd /var/www/dpanel && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1',
            $local->artisanCronLine(['schedule:run'], '/usr/bin/php8.3', '/var/www/dpanel'),
        );
        $this->assertSame(
            'cd /var/www/dpanel && /usr/bin/php8.3 artisan dpanel:trash-backups-prune --days=30 >> /dev/null 2>&1',
            $local->artisanCronLine(['dpanel:trash-backups-prune', '--days=30'], '/usr/bin/php8.3', '/var/www/dpanel'),
        );
        $this->assertSame(
            'cd '.base_path().' && '.$local->panelPhpCli().' artisan schedule:run >> /dev/null 2>&1',
            $local->artisanCronLine(['schedule:run']),
        );
    }

    public function test_unix_lines_quote_what_the_shell_would_split_or_expand(): void
    {
        $local = new LocalCommand('Linux');

        $this->assertSame(
            "cd '/srv/my panel' && php -q '/home/o'\\''neil/x.php' '\$(id)' '' >> /dev/null 2>&1",
            $local->shellLine(['php', '-q', "/home/o'neil/x.php", '$(id)', ''], '/srv/my panel', true),
        );
        $this->assertSame('/usr/bin/php8.2 -q /home/site/public_html/crons/cron.php', $local->shellLine(['/usr/bin/php8.2', '-q', '/home/site/public_html/crons/cron.php']));
    }

    public function test_windows_lines_are_for_cmd(): void
    {
        $local = new LocalCommand('Windows');

        $this->assertSame(
            'cd /d "C:\\Program Files\\dPanel\\panel" && "C:\\Program Files\\PHP\\php.exe" artisan schedule:run >> NUL 2>&1',
            $local->artisanCronLine(['schedule:run'], 'C:\\Program Files\\PHP\\php.exe', 'C:\\Program Files\\dPanel\\panel'),
        );
        $this->assertSame('cd /d C:\\dpanel && C:\\php\\php.exe artisan schedule:run', $local->shellLine(['C:\\php\\php.exe', 'artisan', 'schedule:run'], 'C:\\dpanel'));
        $this->assertSame('"a & b" "C:\\x y\\\\" "<in>|out^"', $local->shellLine(['a & b', 'C:\\x y\\', '<in>|out^']));
    }

    /** @return array<string, array{string}> */
    public static function cmdUnsafe(): array
    {
        return ['percent' => ['%PATH%'], 'quote' => ['say "hi"'], 'bang' => ['hi!'], 'newline' => ["a\nb"]];
    }

    #[DataProvider('cmdUnsafe')]
    public function test_cmd_refuses_what_it_cannot_quote(string $argument): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LocalCommand('Windows'))->shellLine(['php', $argument]);
    }

    public function test_the_php_cli_of_a_version(): void
    {
        $this->assertSame('/usr/bin/php8.2', (new LocalCommand('Linux'))->phpCli('8.2'));
        $this->assertSame(PHP_BINARY, (new LocalCommand('Linux'))->phpCli('8.2; rm'));
        $this->assertSame(PHP_BINARY, (new LocalCommand('Windows'))->phpCli('8.2'));
        $this->assertSame(PHP_BINARY, (new LocalCommand('Darwin'))->phpCli('8.2'));
    }

    private function withPath(string $family, string $path): LocalCommand
    {
        return new class($family, $path) extends LocalCommand
        {
            public function __construct(?string $family, private string $path)
            {
                parent::__construct($family);
            }

            protected function searchPath(): string
            {
                return $this->path;
            }
        };
    }

    public function test_route_source_reads_the_src_word_on_linux_only(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: "1.1.1.1 via 10.0.0.1 dev eth0 src 203.0.113.7 uid 33 \n    cache \n"));

        $this->assertSame('203.0.113.7', (new LocalCommand('Linux'))->routeSource('1.1.1.1'));
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ip', '-4', 'route', 'get', '1.1.1.1']);
        $this->assertSame('', (new LocalCommand('Windows'))->routeSource('1.1.1.1'));
        $this->assertSame('', (new LocalCommand('Linux'))->routeSource('1.1.1.1; reboot'));
    }
}
