<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class CodexController extends Controller
{
    public string $codexBin;

    public function __construct()
    {
        $this->codexBin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? base_path('node_modules/.bin/codex.cmd')
            : base_path('node_modules/.bin/codex');
    }

    public function index(): Response
    {
        return Inertia::render('ServerPanel/Codex/Index', [
            'status' => $this->buildStatusPayload(),
        ]);
    }

    public function login(): JsonResponse
    {
        if (! File::exists($this->codexBin)) {
            return response()->json([
                'success' => false,
                'message' => 'Codex binary not found. Run npm install first.',
                'status' => $this->buildStatusPayload(),
            ], 422);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $launch = $this->startWindowsBrowserLogin();

            return response()->json([
                'success' => $launch['success'],
                'message' => $launch['success']
                    ? 'Codex login started. Open the returned auth URL in popup to continue.'
                    : 'Failed to start Codex login.',
                'auth_url' => $launch['auth_url'],
                'request_id' => $launch['request_id'],
                'output' => $launch['output'],
                'error' => $launch['error'],
                'status' => $this->buildStatusPayload(),
            ], $launch['success'] ? 200 : 422);
        }

        $result = $this->runCodex(['login'], 300);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Codex login completed. Token is remembered by Codex CLI.'
                : 'Codex login failed. See error output.',
            'output' => $result['output'],
            'error' => $result['error'],
            'status' => $this->buildStatusPayload(),
        ], $result['success'] ? 200 : 422);
    }

    public function auth(): JsonResponse
    {
        $version = $this->runCodex(['--version'], 60);

        return response()->json([
            'success' => $version['success'],
            'status' => $this->buildStatusPayload(),
            'output' => $version['output'],
            'error' => $version['error'],
        ], $version['success'] ? 200 : 422);
    }

    public function testMessage(Request $request): JsonResponse
    {
        $message = 'Reply with exactly this text and nothing else: Hello from Codex test route.';
        $result = $this->runCodex(['exec', $message], 180);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'AI test message executed successfully.'
                : 'AI test message failed.',
            'request_message' => $message,
            'ai_output' => $result['output'],
            'ai_error' => $result['error'],
            'status' => $this->buildStatusPayload(),
        ], $result['success'] ? 200 : 422);
    }

    public function loginStatus(string $requestId): JsonResponse
    {
        $stateFile = storage_path("app/codex-login/{$requestId}.json");
        if (! File::exists($stateFile)) {
            return response()->json([
                'success' => false,
                'message' => 'Login request not found.',
            ], 404);
        }

        $state = json_decode((string) File::get($stateFile), true);
        $logPath = $state['log_path'] ?? null;
        $errorLogPath = $state['error_log_path'] ?? null;
        $pid = (int) ($state['pid'] ?? 0);

        $stdoutContent = ($logPath && File::exists($logPath)) ? (string) File::get($logPath) : '';
        $stderrContent = ($errorLogPath && File::exists($errorLogPath)) ? (string) File::get($errorLogPath) : '';
        $logContent = trim($stdoutContent."\n".$stderrContent);
        $authUrl = $this->extractAuthUrl($logContent);
        $isRunning = $pid > 0 ? $this->isWindowsProcessRunning($pid) : false;
        $authCheck = $this->runCodex(['login', 'status'], 30);
        $statusOutput = strtolower(trim((string) $authCheck['output']));
        $statusError = strtolower(trim((string) $authCheck['error']));
        $logLower = strtolower($logContent);
        $authenticated = str_contains($statusOutput, 'logged in')
            || str_contains($statusOutput, 'authenticated')
            || str_contains($logLower, 'successfully logged in')
            || str_contains($logLower, 'login complete')
            || str_contains($statusError, 'already logged in');

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'auth_url' => $authUrl,
            'running' => $isRunning,
            'authenticated' => $authenticated,
            'status' => $this->buildStatusPayload(),
            'login_status_output' => $authCheck['output'],
            'login_status_error' => $authCheck['error'],
            'log_tail' => $this->tail($logContent, 2000),
        ]);
    }

    public function completeFromSuccessUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'success_url' => ['required', 'string', 'max:12000'],
        ]);

        $url = trim((string) $data['success_url']);
        $url = trim($url, " \t\n\r\0\x0B\"'");
        $url = preg_replace('/\s+/', '', $url) ?? $url;

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($scheme !== 'http' || $host !== 'localhost' || $path !== '/success' || $query === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid success URL. Expected http://localhost:1455/success?... format.',
            ], 422);
        }

        $params = [];
        parse_str($query, $params);
        if (empty($params['id_token'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid success URL: missing id_token parameter.',
            ], 422);
        }

        $authCheck = $this->runCodex(['login', 'status'], 30);
        $statusOutput = strtolower(trim((string) $authCheck['output']));
        $statusError = strtolower(trim((string) $authCheck['error']));
        $authenticated = str_contains($statusOutput, 'logged in')
            || str_contains($statusOutput, 'authenticated')
            || str_contains($statusError, 'already logged in');

        if (! $authenticated) {
            $dir = storage_path('app/codex-login');
            if (File::exists($dir)) {
                $recentLogs = collect(File::files($dir))
                    ->filter(fn ($file) => str_ends_with($file->getFilename(), '.log'))
                    ->sortByDesc(fn ($file) => $file->getMTime())
                    ->take(4);

                foreach ($recentLogs as $file) {
                    $content = strtolower((string) File::get($file->getPathname()));
                    if (str_contains($content, 'successfully logged in') || str_contains($content, 'login complete')) {
                        $authenticated = true;
                        break;
                    }
                }
            }
        }

        return response()->json([
            'success' => $authenticated,
            'authenticated' => $authenticated,
            'message' => $authenticated
                ? 'Codex login verified successfully.'
                : 'Success URL received, but Codex status is still not authenticated.',
            'received_params' => [
                'needs_setup' => $params['needs_setup'] ?? null,
                'plan_type' => $params['plan_type'] ?? null,
                'platform_url' => $params['platform_url'] ?? null,
                'org_id' => $params['org_id'] ?? null,
                'project_id' => $params['project_id'] ?? null,
                'has_id_token' => ! empty($params['id_token']),
            ],
            'status' => $this->buildStatusPayload(),
            'login_status_output' => $authCheck['output'],
            'login_status_error' => $authCheck['error'],
        ], $authenticated ? 200 : 422);
    }

    private function runCodex(array $args, int $timeout = 300): array
    {
        $process = new Process([$this->codexBin, ...$args]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout($timeout);
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => trim((string) $process->getOutput()),
            'error' => trim((string) $process->getErrorOutput()),
        ];
    }

    private function buildStatusPayload(): array
    {
        return [
            'binary_path' => $this->codexBin,
            'binary_exists' => File::exists($this->codexBin),
            'openai_key_configured' => filled((string) env('OPENAI_API_KEY')),
        ];
    }

    private function startWindowsBrowserLogin(): array
    {
        $requestId = (string) Str::uuid();
        $baseDir = str_replace("'", "''", base_path());
        $codexPath = str_replace("'", "''", $this->codexBin);
        $dir = storage_path('app/codex-login');
        File::ensureDirectoryExists($dir);
        $logPath = "{$dir}/{$requestId}.out.log";
        $errorLogPath = "{$dir}/{$requestId}.err.log";
        $statePath = "{$dir}/{$requestId}.json";
        $escapedLog = str_replace("'", "''", $logPath);
        $escapedErrorLog = str_replace("'", "''", $errorLogPath);

        $command = <<<PS
powershell -NoProfile -ExecutionPolicy Bypass -Command "\$p = Start-Process -FilePath '{$codexPath}' -ArgumentList 'login' -WorkingDirectory '{$baseDir}' -RedirectStandardOutput '{$escapedLog}' -RedirectStandardError '{$escapedErrorLog}' -PassThru; Write-Output \$p.Id"
PS;

        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(30);
        $process->run();

        $pid = (int) trim((string) $process->getOutput());
        File::put($statePath, json_encode([
            'request_id' => $requestId,
            'pid' => $pid,
            'log_path' => $logPath,
            'error_log_path' => $errorLogPath,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $authUrl = null;
        $started = microtime(true);
        while (microtime(true) - $started < 8.0) {
            usleep(250000);
            $stdout = File::exists($logPath) ? (string) File::get($logPath) : '';
            $stderr = File::exists($errorLogPath) ? (string) File::get($errorLogPath) : '';
            if ($stdout === '' && $stderr === '') {
                continue;
            }
            $content = $stdout."\n".$stderr;
            $authUrl = $this->extractAuthUrl($content);
            if ($authUrl) {
                break;
            }
        }

        return [
            'success' => $process->isSuccessful() && $pid > 0,
            'request_id' => $requestId,
            'auth_url' => $authUrl,
            'output' => trim((string) $process->getOutput()),
            'error' => trim((string) $process->getErrorOutput()),
        ];
    }

    private function extractAuthUrl(string $text): ?string
    {
        $normalized = preg_replace('/\s+/', '', $text) ?? $text;
        if (preg_match('/https:\/\/auth\.openai\.com\/oauth\/authorize\?[^"\']+/i', $normalized, $matches) === 1) {
            return trim($matches[0]);
        }

        return null;
    }

    private function isWindowsProcessRunning(int $pid): bool
    {
        $process = new Process(['tasklist', '/FI', "PID eq {$pid}"]);
        $process->setTimeout(10);
        $process->run();
        if (! $process->isSuccessful()) {
            return false;
        }

        return str_contains($process->getOutput(), (string) $pid);
    }

    private function tail(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : mb_substr($text, -1 * $length);
    }
}
