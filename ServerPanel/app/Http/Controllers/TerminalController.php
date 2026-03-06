<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    private const SESSION_CWD_KEY = 'terminal.cwd';

    /**
     * Show terminal page.
     */
    public function index(Request $request): Response
    {
        $currentDir = $this->resolveWorkingDirectory(
            is_string($request->session()->get(self::SESSION_CWD_KEY))
                ? (string) $request->session()->get(self::SESSION_CWD_KEY)
                : null,
        );
        $request->session()->put(self::SESSION_CWD_KEY, $currentDir);

        return Inertia::render('Server/Terminal', [
            'quickCommands' => [
                'whoami',
                'pwd',
                'ls -la',
                'cd ..',
                'cd ~',
                'php -v',
                'df -h',
                'free -m',
                'uptime',
            ],
            'currentDir' => $currentDir,
        ]);
    }

    /**
     * Execute command and return output in flash session.
     */
    public function execute(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:500'],
            'cwd' => ['nullable', 'string', 'max:2000'],
        ]);

        $command = trim($validated['command']);
        $currentDir = $this->resolveWorkingDirectory(
            (string) ($validated['cwd'] ?? $request->session()->get(self::SESSION_CWD_KEY, '')),
        );
        $request->session()->put(self::SESSION_CWD_KEY, $currentDir);

        if ($this->isBlocked($command)) {
            $payload = $this->buildTerminalPayload($command, [], 1, $currentDir);
            $error = 'Blocked command for safety.';

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $error,
                    'terminal' => $payload,
                ], 422);
            }

            return redirect()
                ->route('terminal.index')
                ->with('error', $error)
                ->with('terminal', $payload);
        }

        if ($this->isCdCommand($command)) {
            [$nextDir, $message, $exitCode] = $this->handleCdCommand($currentDir, $command);
            $request->session()->put(self::SESSION_CWD_KEY, $nextDir);
            $payload = $this->buildTerminalPayload($command, [$message], $exitCode, $nextDir);

            if ($request->expectsJson()) {
                return response()->json([
                    'terminal' => $payload,
                ]);
            }

            return redirect()
                ->route('terminal.index')
                ->with('terminal', $payload);
        }

        $result = Process::path($currentDir)->timeout(20)->run($command);
        $payload = $this->buildTerminalPayload(
            $command,
            preg_split('/\r\n|\r|\n/', trim($result->output()."\n".$result->errorOutput())),
            $result->exitCode(),
            $currentDir,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'terminal' => $payload,
            ]);
        }

        return redirect()
            ->route('terminal.index')
            ->with('terminal', $payload);
    }

    /**
     * @param  array<int,string>  $output
     * @return array{command:string,output:array<int,string>,exit_code:int,executed_at:string,current_dir:string}
     */
    private function buildTerminalPayload(string $command, array $output, int $exitCode, string $currentDir): array
    {
        return [
            'command' => $command,
            'output' => array_values(array_map(static fn ($line) => (string) $line, $output)),
            'exit_code' => $exitCode,
            'executed_at' => now()->toDateTimeString(),
            'current_dir' => $currentDir,
        ];
    }

    private function resolveWorkingDirectory(?string $candidate): string
    {
        $fallbackCandidate = $this->terminalBaseDirectory();
        $fallback = realpath($fallbackCandidate);
        if ($fallback === false || ! is_dir($fallback)) {
            $fallback = base_path();
        }
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return $fallback;
        }

        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            return $fallback;
        }

        return $real;
    }

    private function isCdCommand(string $command): bool
    {
        return preg_match('/^cd(?:\s+.*)?$/i', trim($command)) === 1;
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function handleCdCommand(string $currentDir, string $command): array
    {
        $target = trim((string) preg_replace('/^cd\s*/i', '', trim($command)));
        if ($target === '' || $target === '~') {
            $home = getenv('HOME') ?: getenv('USERPROFILE');
            $resolved = $this->resolveWorkingDirectory($home ?: $this->terminalBaseDirectory() ?: $currentDir);

            return [$resolved, "Moved to {$resolved}", 0];
        }

        $target = str_replace('\\', DIRECTORY_SEPARATOR, $target);
        $isAbsolute = preg_match('/^(\/|[A-Za-z]:[\\\\\/])/', $target) === 1;
        $candidatePath = $isAbsolute ? $target : rtrim($currentDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$target;
        $resolved = realpath($candidatePath);

        if ($resolved === false || ! is_dir($resolved)) {
            return [$currentDir, "cd: no such directory: {$target}", 1];
        }

        return [$resolved, "Moved to {$resolved}", 0];
    }

    private function isBlocked(string $command): bool
    {
        $blockedPatterns = [
            '/\brm\s+-rf\b/i',
            '/\bmkfs\b/i',
            '/\bdd\s+if=/i',
            '/\bshutdown\b/i',
            '/\breboot\b/i',
            '/\bpoweroff\b/i',
            '/\bhalt\b/i',
            '/:\(\)\s*\{\s*:\|:\s*&\s*\};:/',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    private function terminalBaseDirectory(): string
    {
        $configured = trim((string) config('app.server_base_dir', ''));
        if ($configured !== '') {
            return $configured;
        }

        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return dirname(base_path());
        }

        return '/home';
    }
}
