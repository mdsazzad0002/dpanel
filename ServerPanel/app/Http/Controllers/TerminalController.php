<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    /**
     * Show terminal page.
     */
    public function index(): Response
    {
        return Inertia::render('Server/Terminal', [
            'quickCommands' => [
                'whoami',
                'pwd',
                'ls -la',
                'php -v',
                'df -h',
                'free -m',
                'uptime',
            ],
        ]);
    }

    /**
     * Execute command and return output in flash session.
     */
    public function execute(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:500'],
        ]);

        $command = trim($validated['command']);

        if ($this->isBlocked($command)) {
            return redirect()
                ->route('terminal.index')
                ->with('error', 'Blocked command for safety.')
                ->with('terminal', [
                    'command' => $command,
                    'output' => [],
                    'exit_code' => 1,
                    'executed_at' => now()->toDateTimeString(),
                ]);
        }

        $result = Process::timeout(15)->run($command);

        return redirect()
            ->route('terminal.index')
            ->with('terminal', [
                'command' => $command,
                'output' => preg_split('/\r\n|\r|\n/', trim($result->output()."\n".$result->errorOutput())),
                'exit_code' => $result->exitCode(),
                'executed_at' => now()->toDateTimeString(),
            ]);
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
}

