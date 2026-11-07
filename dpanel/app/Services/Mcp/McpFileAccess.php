<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\PathService;

/**
 * File operations for the MCP file tools, built on McpCommandExecutor so they
 * work the same on the panel host and on managed servers over SSH.
 *
 * Writes are atomic (temp file + mv), keep the original owner and mode, and
 * copy the old file under mcp.backup_root first. Deletes move into its trash
 * folder, so every change an AI client makes can be undone.
 */
class McpFileAccess
{
    /** Largest file write_file / edit_file will handle. */
    public const MAX_WRITE_BYTES = 1048576;

    /**
     * Raw bytes per write command. The command travels as one argv string
     * (`bash -c`), which Linux caps at 128 KiB, and base64 plus the line
     * breaks below adds about 80%.
     */
    private const WRITE_CHUNK_BYTES = 48000;

    /** The same under McpCommandExecutor::WINDOWS_MAX_COMMAND_CHARS, with room for the paths. */
    private const WINDOWS_WRITE_CHUNK_BYTES = 3000;

    /** Never written, moved or deleted over MCP. */
    private const PROTECTED_PATHS = ['/proc', '/sys', '/dev', '/boot', '/etc/shadow', '/etc/gshadow', '/etc/passwd', '/etc/group', '/etc/sudoers', '/etc/sudoers.d', '/etc/dpanel/mcp-local.conf'];

    public function __construct(
        private readonly McpCommandExecutor $executor,
        private readonly LocalHostAccess $localAccess,
    ) {}

    public function server(mixed $serverId): ?Server
    {
        return $this->executor->resolveServer($serverId);
    }

    /**
     * Validate an absolute path and normalize "//" and "/./" away. A path on a
     * Windows panel host must start at a drive; a managed server is always POSIX.
     */
    public function path(mixed $value, string $field = 'path', mixed $serverId = null): string
    {
        $path = trim((string) $value);
        $onDrives = PathService::hostUsesDrivePaths() && $this->onDrives($this->server($serverId));
        if ($onDrives) {
            $path = PathService::normalizeAbsolutePath($path);
        }

        if (! $this->accepts($path, $serverId) || strlen($path) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new McpToolException("{$field} must be an absolute path.");
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment) => $segment !== '' && $segment !== '.'));
        if (in_array('..', $segments, true)) {
            throw new McpToolException("{$field} must not contain \"..\".");
        }

        if (! $onDrives) {
            return '/'.implode('/', $segments);
        }

        if (! PathService::isPlainDrivePath($path)) {
            throw new McpToolException("{$field} must be a plain path on a drive: no streams, 8.3 names, trailing dots, wildcards or device names.");
        }

        return $segments[0].'/'.implode('/', array_slice($segments, 1));
    }

    /** Whether $path has the shape of an absolute path on the target. */
    public function accepts(string $path, mixed $serverId = null): bool
    {
        return PathService::hostUsesDrivePaths() && $this->onDrives($this->server($serverId))
            ? PathService::isDrivePath($path)
            : str_starts_with($path, '/');
    }

    /** Whether $server means this host, and this host names files by drive. */
    private function onDrives(?Server $server): bool
    {
        return $server === null && PathService::hostUsesDrivePaths();
    }

    /** NTFS ignores case, so ".ENV" must meet the same guards as ".env". */
    private function comparable(?Server $server, string $path): string
    {
        return $this->onDrives($server) ? mb_strtolower($path) : $path;
    }

    /**
     * Read-only tokens may not read secrets (keys, .env, shadow …), also
     * when reached through a symlink.
     */
    public function guardRead(?Server $server, string $path, McpAccessToken $token): void
    {
        if ($token->allowsMutations()) {
            return;
        }

        foreach ([$path, $this->resolve($server, $path, $token)] as $candidate) {
            foreach (McpCommandPolicy::SENSITIVE_PATHS as $sensitive) {
                if (str_contains($this->comparable($server, $candidate), $sensitive)) {
                    throw new McpToolException("Read-only MCP tokens cannot read sensitive paths ({$sensitive}).");
                }
            }
        }
    }

    /**
     * @return string The path with symlinks resolved, which is what gets written.
     */
    public function guardWrite(?Server $server, string $path, McpAccessToken $token): string
    {
        if (! $token->allowsMutations()) {
            throw new McpToolException('This MCP token is read-only. Create a full-access token in dPanel (Settings → MCP Access) to change files.');
        }
        if ($server === null && $this->localAccess->level() === LocalHostAccess::READ) {
            throw new McpToolException('The panel host only allows read-only MCP commands. An admin can allow fixes with: sudo dpanel mcp local on full');
        }

        $resolved = $this->resolve($server, $path, $token);

        foreach ([$path, $resolved] as $candidate) {
            foreach ($this->protectedPaths($server) as $protected) {
                $left = $this->comparable($server, $candidate);
                $right = $this->comparable($server, $protected);
                if ($left === $right || str_starts_with($left, $right.'/')) {
                    throw new McpToolException("{$protected} cannot be changed over MCP.");
                }
            }
        }

        return $resolved;
    }

    /**
     * Refuse removing, moving or recursively changing top-level directories
     * such as /etc, /var/www or /home/user.
     */
    public function guardTree(string $path): void
    {
        if (substr_count($path, '/') < 3) {
            throw new McpToolException("{$path} is too close to / to do that over MCP; pick a deeper path or use run_command.");
        }
    }

    /**
     * Run a command built by a file tool and return its output, or throw
     * with the command's own message when it exits non-zero.
     */
    public function run(?Server $server, string $command, McpAccessToken $token, ?int $outputLimit = null): string
    {
        $result = $this->executor->run($server, $command, $token, trusted: true, outputLimit: $outputLimit);

        if ((int) ($result['exit_code'] ?? 1) !== 0) {
            $message = trim(((string) ($result['output'] ?? ''))."\n".((string) ($result['error_output'] ?? '')));

            throw new McpToolException($message !== '' ? $message : 'The command failed with exit code '.($result['exit_code'] ?? '?').'.');
        }

        return (string) ($result['output'] ?? '');
    }

    public function resolve(?Server $server, string $path, McpAccessToken $token): string
    {
        $quoted = McpCommandExecutor::quote($path);
        $follow = "readlink -f -- {$quoted} 2>/dev/null || printf '%s\\n' {$quoted}";

        if ($this->onDrives($server)) {
            // bash answers with its own names (/tmp/x, /c/x); the guards compare
            // drive paths. A link that leaves the drives, such as to UNC, is refused.
            $resolved = trim($this->run($server, "cygpath -m -- \"\$({$follow})\"", $token));
            if (! PathService::isDrivePath($resolved)) {
                throw new McpToolException("Could not resolve {$path} to a path on a drive.");
            }

            return $resolved;
        }

        $resolved = trim($this->run($server, $follow, $token));

        return $resolved !== '' && str_starts_with($resolved, '/') ? $resolved : $path;
    }

    /**
     * Whole file contents, for edits.
     */
    public function readWhole(?Server $server, string $path, McpAccessToken $token): string
    {
        $quoted = McpCommandExecutor::quote($path);
        $max = self::MAX_WRITE_BYTES;

        $output = $this->run($server, implode("\n", [
            "test -f {$quoted} || { echo 'Not a regular file:' {$quoted}; exit 2; }",
            "size=\$(stat -c %s -- {$quoted})",
            "[ \"\$size\" -le {$max} ] || { echo \"File is \$size bytes; MCP edits handle up to {$max} bytes. Use run_command (sed) instead.\"; exit 3; }",
            "base64 -w0 -- {$quoted}",
        ]), $token, outputLimit: (int) (self::MAX_WRITE_BYTES * 1.4) + 1024);

        $content = base64_decode(trim($output), true);
        if ($content === false) {
            throw new McpToolException('Could not read '.$path.': unexpected output.');
        }

        return $content;
    }

    /**
     * Atomically replace (or create) a file.
     *
     * @param  string|null  $expectedSha256  Refuse when the file changed since it was read.
     * @return array{path: string, bytes: int, sha256: string, created: bool, backup_path: string|null}
     */
    public function write(?Server $server, string $path, string $content, McpAccessToken $token, ?string $expectedSha256 = null, bool $backup = true, bool $createDirs = false): array
    {
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            throw new McpToolException('content is larger than '.self::MAX_WRITE_BYTES.' bytes.');
        }

        $directory = dirname($path);
        $temp = $directory.'/.dpanel-mcp-'.bin2hex(random_bytes(6)).'.tmp';
        $qDir = McpCommandExecutor::quote($directory);
        $qTemp = McpCommandExecutor::quote($temp);

        try {
            foreach (str_split($content, $this->onDrives($server) ? self::WINDOWS_WRITE_CHUNK_BYTES : self::WRITE_CHUNK_BYTES) ?: [''] as $index => $chunk) {
                // Line breaks every 3 characters keep the panel's blocked-word
                // check (mkfs, reboot, …) from matching random base64.
                $encoded = rtrim(chunk_split(base64_encode($chunk), 3, "\n"));
                $write = "printf '%s' '{$encoded}' | base64 -d ".($index === 0 ? '>' : '>>')." {$qTemp}";

                $this->run($server, $index === 0
                    ? ($createDirs ? "mkdir -p -- {$qDir}\n" : '')."test -d {$qDir} || { echo 'Directory does not exist:' {$qDir} '(pass create_dirs=true)'; exit 2; }\n(umask 077; {$write})"
                    : $write, $token);
            }

            $output = $this->run($server, $this->commitScript($server, $path, $temp, $expectedSha256, $backup), $token);
        } catch (McpToolException $exception) {
            try {
                $this->run($server, "rm -f -- {$qTemp}", $token);
            } catch (McpToolException) {
                // Best effort; the commit script removes it on its own failures.
            }

            throw $exception;
        }

        $sha256 = hash('sha256', $content);
        preg_match('/^created=(\d)$/m', $output, $created);
        preg_match('/^backup=(.*)$/m', $output, $backupPath);
        preg_match('/^sha256=([0-9a-f]{64})$/m', $output, $written);

        if (($written[1] ?? null) !== $sha256) {
            throw new McpToolException("Wrote {$path}, but its checksum does not match what was sent. Read it back before continuing.");
        }

        return [
            'path' => $path,
            'bytes' => strlen($content),
            'sha256' => $sha256,
            'created' => ($created[1] ?? '0') === '1',
            'backup_path' => isset($backupPath[1]) && $backupPath[1] !== '' ? $backupPath[1] : null,
        ];
    }

    /**
     * Move a file or directory into the MCP trash instead of deleting it.
     */
    public function trash(?Server $server, string $path, bool $recursive, McpAccessToken $token): string
    {
        $quoted = McpCommandExecutor::quote($path);
        $target = $this->backupTarget($server, 'trash', $path);
        $qTarget = McpCommandExecutor::quote($target);
        $qParent = McpCommandExecutor::quote(dirname($target));

        $this->run($server, implode("\n", [
            "{ [ -e {$quoted} ] || [ -L {$quoted} ]; } || { echo 'No such file or directory:' {$quoted}; exit 2; }",
            $recursive ? '' : "if [ -d {$quoted} ] && [ ! -L {$quoted} ]; then echo {$quoted} 'is a directory; pass recursive=true to delete it.'; exit 2; fi",
            "mkdir -p -- {$qParent} && mv -- {$quoted} {$qTarget}",
        ]), $token);

        return $target;
    }

    private function commitScript(?Server $server, string $path, string $temp, ?string $expectedSha256, bool $backup): string
    {
        $backupPath = $this->backupTarget($server, 'files', $path);

        return implode("\n", array_filter([
            'T='.McpCommandExecutor::quote($path),
            'N='.McpCommandExecutor::quote($temp),
            'B='.McpCommandExecutor::quote($backupPath),
            'fail() { echo "$1"; rm -f -- "$N"; exit "$2"; }',
            'if [ -e "$T" ]; then',
            '  [ -f "$T" ] || fail "Not a regular file: $T" 2',
            $expectedSha256 !== null
                ? '  current=$(sha256sum -- "$T" | cut -d" " -f1); [ "$current" = '.McpCommandExecutor::quote($expectedSha256).' ] || fail "$T changed since it was read (sha256 is now $current). Read it again before writing." 3'
                : null,
            $backup
                ? '  { mkdir -p -- "$(dirname -- "$B")" && cp -p -- "$T" "$B"; } || fail "Could not back up $T to $B (pass backup=false to skip)." 4'
                : null,
            '  chown --reference="$T" -- "$N" 2>/dev/null || true',
            '  chmod --reference="$T" -- "$N" || fail "Could not copy the mode of $T." 4',
            $backup ? '  echo "backup=$B"' : null,
            '  echo created=0',
            'else',
            $expectedSha256 !== null ? '  fail "$T no longer exists. Read it again before writing." 3' : null,
            '  chown --reference="$(dirname -- "$T")" -- "$N" 2>/dev/null || true',
            '  chmod 644 -- "$N"',
            '  echo created=1',
            'fi',
            'mv -f -- "$N" "$T" || fail "Could not replace $T." 5',
            'echo "sha256=$(sha256sum -- "$T" | cut -d" " -f1)"',
        ]));
    }

    public static function backupRoot(): string
    {
        return rtrim((string) config('mcp.backup_root', '/var/backups/dpanel-mcp'), '/');
    }

    /**
     * @return list<string>
     */
    private function protectedPaths(?Server $server): array
    {
        if (! $this->onDrives($server)) {
            return [...self::PROTECTED_PATHS, self::backupRoot()];
        }

        return [
            PathService::normalizeAbsolutePath((string) (getenv('SystemRoot') ?: 'C:/Windows')),
            PathService::normalizeAbsolutePath((string) config('mcp.local_config_path')),
            $this->backupRootOn($server),
        ];
    }

    /** The default root is a POSIX path, which means nothing on a drive. */
    private function backupRootOn(?Server $server): string
    {
        $root = self::backupRoot();
        if (! $this->onDrives($server)) {
            return $root;
        }

        return rtrim(PathService::normalizeAbsolutePath(PathService::isDrivePath($root) ? $root : storage_path('app/mcp-backups')), '/');
    }

    /** Where $path is kept under the backup root; "C:/x" becomes "<dir>/C/x". */
    private function backupTarget(?Server $server, string $kind, string $path): string
    {
        $directory = $this->backupRootOn($server).'/'.$kind.'/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));

        return $directory.(PathService::isDrivePath($path) ? '/'.$path[0].substr($path, 2) : $path);
    }
}
