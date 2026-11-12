<?php

namespace App\Support\Platform;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Runs programs on the machine PHP runs on (plans/platform-spec.md, LocalCommand).
 *
 * Commands are argv lists: no shell parses them, so user input cannot become
 * shell syntax on any OS. Pipes and redirects are done in PHP on the output.
 *
 * Tools that only some systems have sit behind supports(). These are host
 * capabilities, read from the OS PHP runs on; drust's GET /capabilities
 * (PlatformCapabilities) says what drust can do, which is a different question.
 */
class LocalCommand
{
    /** sh, ps, id, hostname, sudo: every Unix (Linux and macOS). */
    public const UNIX_TOOLS = 'unix_tools';

    /** /proc, ip, ss, nproc, lscpu, dpkg, dmidecode, getent, useradd, ufw, sshd: Linux. */
    public const LINUX_TOOLS = 'linux_tools';

    /** systemctl and journalctl: Linux. */
    public const SYSTEMD = 'systemd';

    /** postconf, postqueue, doveconf and the Dovecot files: Linux, where the mail backend is Postfix. */
    public const POSTFIX = 'postfix';

    private const DEFAULT_TIMEOUT = 60;

    /** Arguments the Unix shell reads as one plain word, left unquoted so Linux lines stay as before. */
    private const UNIX_PLAIN = '/^[A-Za-z0-9_@%+=:,.\/-]+$/';

    /** The same for cmd.exe, which also takes backslashes in a plain word. */
    private const WINDOWS_PLAIN = '/^[A-Za-z0-9_@+=:,.\\\\\/-]+$/';

    /** @param  string|null  $family  PHP_OS_FAMILY to act as; null is this machine. Tests pass another OS. */
    public function __construct(private readonly ?string $family = null) {}

    /** The OS name as drust reports it: linux, windows, macos or other. */
    public function os(): string
    {
        return match ($this->family ?? PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'other',
        };
    }

    public function isWindows(): bool
    {
        return $this->os() === 'windows';
    }

    public function isLinux(): bool
    {
        return $this->os() === 'linux';
    }

    /** Whether this host has the tools in one of the class constants. */
    public function supports(string $tools): bool
    {
        return match ($tools) {
            self::UNIX_TOOLS => ! $this->isWindows(),
            self::LINUX_TOOLS, self::SYSTEMD, self::POSTFIX => $this->isLinux(),
            default => throw new InvalidArgumentException("Unknown tool set: {$tools}"),
        };
    }

    /**
     * Run a program and wait for it.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(array $argv, ?string $cwd = null, ?int $timeout = self::DEFAULT_TIMEOUT, array $env = [], ?string $input = null): ProcessResult
    {
        $process = Process::timeout(max(1, $timeout ?? self::DEFAULT_TIMEOUT));
        if ($cwd !== null) {
            $process = $process->path($cwd);
        }
        if ($env !== []) {
            $process = $process->env($env);
        }
        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run($this->argv($argv));
    }

    /**
     * What a program writes to stdout, whatever its exit code; null when it
     * could not run or wrote nothing. The same answer shell_exec() gave with
     * "2>/dev/null", without a shell.
     *
     * @param  list<string>  $argv
     */
    public function output(array $argv, int $timeout = 15): ?string
    {
        try {
            $output = $this->run($argv, timeout: $timeout)->output();
        } catch (\Throwable) {
            return null;
        }

        return $output === '' ? null : $output;
    }

    /**
     * stdout followed by stderr, whatever the exit code; '' when the program
     * could not run. What shell_exec() gave with "2>&1".
     *
     * @param  list<string>  $argv
     */
    public function combinedOutput(array $argv, int $timeout = 15): string
    {
        try {
            $result = $this->run($argv, timeout: $timeout);
        } catch (\Throwable) {
            return '';
        }

        return $result->output().$result->errorOutput();
    }

    /** Whether a program ran and exited with 0. */
    public function succeeds(array $argv, int $timeout = 15): bool
    {
        try {
            return $this->run($argv, timeout: $timeout)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The full path of a program on PATH, or null. What `command -v` answered,
     * with PATHEXT on Windows so `mysqldump` finds mysqldump.exe.
     */
    public function find(string $program): ?string
    {
        if ($program === '' || str_contains($program, "\0")) {
            return null;
        }

        $windows = $this->isWindows();
        $extensions = $windows ? $this->windowsExtensions($program) : [''];

        if (str_contains($program, '/') || ($windows && str_contains($program, '\\'))) {
            foreach ($extensions as $extension) {
                if ($this->isProgram($program.$extension)) {
                    return $program.$extension;
                }
            }

            return null;
        }

        foreach (explode($windows ? ';' : ':', $this->searchPath()) as $directory) {
            $directory = trim($directory, $windows ? " \"" : '');
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\').($windows ? '\\' : '/').$program.$extension;
                if ($this->isProgram($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * One command line for the system shell: `/bin/sh -c` on Unix, `cmd /C`
     * on Windows. Only for strings another program stores and runs later,
     * such as a cron job's command (Spec 6.13); everything else uses run().
     *
     * @param  list<string>  $argv
     * @param  bool  $discardOutput  Send stdout and stderr to the null device.
     */
    public function shellLine(array $argv, ?string $cwd = null, bool $discardOutput = false): string
    {
        $windows = $this->isWindows();
        $line = implode(' ', array_map(fn (string $argument) => $this->quote($argument), $this->argv($argv)));

        if ($cwd !== null) {
            $line = ($windows ? 'cd /d ' : 'cd ').$this->quote($cwd).' && '.$line;
        }
        if ($discardOutput) {
            $line .= $windows ? ' >> NUL 2>&1' : ' >> /dev/null 2>&1';
        }

        return $line;
    }

    /**
     * The cron command that runs an artisan command of this panel, its output
     * thrown away.
     *
     * @param  list<string>  $arguments
     */
    public function artisanCronLine(array $arguments, ?string $phpBinary = null, ?string $basePath = null): string
    {
        return $this->shellLine([$phpBinary ?? PHP_BINARY, 'artisan', ...$arguments], $basePath ?? base_path(), true);
    }

    /**
     * The PHP CLI of one installed version. Linux keeps Debian's
     * /usr/bin/phpX.Y; other systems have no per-version CLI yet (F2), so the
     * panel's own PHP is the best known one.
     */
    public function phpCli(string $version): string
    {
        if ($this->isLinux() && preg_match('/^\d+\.\d+$/', $version) === 1) {
            return "/usr/bin/php{$version}";
        }

        return PHP_BINARY;
    }

    /**
     * The local address Linux would send from to reach $destination, read
     * from `ip route get`; '' elsewhere or when there is no route.
     */
    public function routeSource(string $destination): string
    {
        if (! $this->supports(self::LINUX_TOOLS) || filter_var($destination, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $family = str_contains($destination, ':') ? '-6' : '-4';
        $words = preg_split('/\s+/', trim((string) $this->output(['ip', $family, 'route', 'get', $destination]))) ?: [];
        $at = array_search('src', $words, true);

        return $at === false ? '' : (string) ($words[$at + 1] ?? '');
    }

    /** Quote one argument for the shell shellLine() writes for. */
    public function quote(string $argument): string
    {
        if (str_contains($argument, "\0")) {
            throw new InvalidArgumentException('Command arguments must not contain null bytes.');
        }

        if ($this->isWindows()) {
            if (preg_match(self::WINDOWS_PLAIN, $argument) === 1) {
                return $argument;
            }
            // cmd.exe has no escape that works inside quotes for these.
            if (preg_match('/["%!\r\n]/', $argument) === 1) {
                throw new InvalidArgumentException('This argument cannot be passed safely through cmd.exe: '.$argument);
            }

            // A backslash before the closing quote would escape it for the program's argv parser.
            return '"'.preg_replace('/(\\\\+)$/', '$1$1', $argument).'"';
        }

        return preg_match(self::UNIX_PLAIN, $argument) === 1
            ? $argument
            : "'".str_replace("'", "'\\''", $argument)."'";
    }

    protected function searchPath(): string
    {
        return (string) getenv('PATH');
    }

    protected function isProgram(string $path): bool
    {
        return is_file($path) && ($this->isWindows() || is_executable($path));
    }

    /** @return list<string> */
    private function windowsExtensions(string $program): array
    {
        $extensions = array_filter(array_map('trim', explode(';', (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'))));
        $hasExtension = pathinfo($program, PATHINFO_EXTENSION) !== '';

        return $hasExtension ? ['', ...$extensions] : array_values(array_map('strtolower', $extensions));
    }

    /**
     * @param  array<mixed>  $argv
     * @return list<string>
     */
    private function argv(array $argv): array
    {
        if ($argv === [] || ! array_is_list($argv)) {
            throw new InvalidArgumentException('A command is a non-empty list of arguments.');
        }

        foreach ($argv as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Command arguments must be strings without null bytes.');
            }
        }
        if (trim($argv[0]) === '') {
            throw new InvalidArgumentException('A command needs a program.');
        }

        return $argv;
    }
}
