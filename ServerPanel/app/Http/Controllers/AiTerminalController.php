<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ServerPanel\ChatGptService;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiTerminalController extends Controller
{
    private const SINGLE_SESSION_ID = 'main';
    private const BASE_DIR = 'serverpanel';
    private const APPROVAL_YES = '1';
    private const APPROVAL_NO = '2';
    private const APPROVAL_SHOW = '3';

    public function __construct(
        private readonly ChatGptService $chatGpt,
        private readonly SshClientService $ssh,
    ) {
    }

    public function index(): Response
    {
        $this->writeSession(self::SINGLE_SESSION_ID);
        $active = $this->loadSessionById(self::SINGLE_SESSION_ID);

        return Inertia::render('ServerPanel/AiTerminal/Index', [
            'sessions' => [$active],
            'activeSession' => $active,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function start(): RedirectResponse
    {
        $this->writeSession(self::SINGLE_SESSION_ID);

        return redirect()->route('ai-terminal.index');
    }

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
            'server_id' => ['nullable', 'exists:servers,id'],
        ]);

        $input = trim((string) $data['message']);
        $server = isset($data['server_id'])
            ? Server::query()->find($data['server_id'])
            : null;

        $sessionId = self::SINGLE_SESSION_ID;

        $this->appendMessage($sessionId, 'user', 'USER', $input);

        $result = $this->decide($sessionId, $input, $server);

        $source = strtoupper((string) ($result['source'] ?? 'AI'));
        $message = (string) ($result['message'] ?? '');

        $this->appendMessage($sessionId, 'ai', $source, $message);
        $this->appendEngineLog("session={$sessionId} source={$source} action=".($result['action'] ?? 'chat'));

        return response()->json([
            'ok' => true,
            'source' => $source,
            'message' => $message,
            'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
            'status' => $result['status'] ?? 'ok',
            'action' => $result['action'] ?? 'chat',
            'suggestion' => $result['suggestion'] ?? null,
            'command_used' => $result['command_used'] ?? null,
            'risk_level' => $result['risk_level'] ?? null,
            'messages' => [
                [
                    'role' => 'user',
                    'source' => 'USER',
                    'message' => $input,
                    'created_at' => now()->toIso8601String(),
                ],
                [
                    'role' => 'ai',
                    'source' => $source,
                    'message' => $message,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    public function stream(Request $request): StreamedResponse
    {
        $input = trim((string) $request->query('message', ''));
        $serverId = $request->query('server_id');
        $server = $serverId ? Server::query()->find($serverId) : null;
        $sessionId = self::SINGLE_SESSION_ID;

        $this->appendMessage($sessionId, 'user', 'USER', $input);

        return response()->stream(function () use ($sessionId, $input, $server): void {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');

            $approvedFromPrompt = false;
            $approvalDecision = $this->resolveApprovalChoice($sessionId, $input);
            if ($approvalDecision !== null) {
                if (($approvalDecision['action'] ?? '') === 'approve_execute') {
                    $input = (string) $approvalDecision['command'];
                    $approvedFromPrompt = true;
                } else {
                    $message = (string) ($approvalDecision['message'] ?? '');
                    $source = strtoupper((string) ($approvalDecision['source'] ?? 'AI'));
                $this->appendMessage($sessionId, 'ai', $source, $message);
                if ($server) {
                    $this->emitSse('cwd_update', ['cwd' => $this->getWorkingDir($sessionId, $server->id)]);
                }
                $this->emitSse('done', [
                    'source' => $source,
                    'message' => $message,
                    'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
                    'status' => (string) ($approvalDecision['status'] ?? 'ok'),
                ]);
                return;
                }
            }

            $normalized = $this->normalize($input);
            $resolvedCommand = $this->resolveNaturalCommand($normalized);
            if ($resolvedCommand !== null) {
                $normalized = $resolvedCommand;
                $mode = 'command';
            } else {
                $mode = $this->classifyMode($normalized);
            }

            if ($mode !== 'command' || ! $server) {
                $ai = $this->aiReply($input, $server);
                $message = (string) ($ai['message'] ?? '');
                $this->appendMessage($sessionId, 'ai', 'AI', $message);
                if ($server) {
                    $this->emitSse('cwd_update', ['cwd' => $this->getWorkingDir($sessionId, $server->id)]);
                }
                $this->emitSse('done', [
                    'source' => 'AI',
                    'message' => $message,
                    'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
                    'status' => 'ok',
                ]);
                return;
            }

            if ($this->commandRiskLevel($normalized) === 'blocked') {
                $blocked = $this->blockedResponse($normalized);
                $message = (string) ($blocked['message'] ?? 'Blocked command');
                $this->appendMessage($sessionId, 'ai', 'AI', $message);
                if ($server) {
                    $this->emitSse('cwd_update', ['cwd' => $this->getWorkingDir($sessionId, $server->id)]);
                }
                $this->emitSse('done', [
                    'source' => 'AI',
                    'message' => $message,
                    'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
                    'status' => 'blocked',
                ]);
                return;
            }

            if ($this->commandRiskLevel($normalized) === 'approval_required' && ! $approvedFromPrompt) {
                $this->savePendingApproval($sessionId, $normalized);
                $approval = $this->approvalPromptResponse($normalized);
                $message = (string) ($approval['message'] ?? '');
                $this->appendMessage($sessionId, 'ai', 'AI', $message);
                if ($server) {
                    $this->emitSse('cwd_update', ['cwd' => $this->getWorkingDir($sessionId, $server->id)]);
                }
                $this->emitSse('done', [
                    'source' => 'AI',
                    'message' => $message,
                    'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
                    'status' => 'approval_required',
                ]);
                return;
            }

            try {
                $commandToRun = $this->buildCommandWithWorkingDir($sessionId, $normalized, $server->id);
                $result = $this->ssh->executeOnServerStreaming(
                    $server,
                    $commandToRun,
                    function (string $line): void {
                        $this->emitSse('line', ['line' => $line]);
                    }
                );
            } catch (\Throwable $e) {
                $this->emitSse('error', ['message' => $e->getMessage()]);
                return;
            }

            $output = (string) ($result['output'] ?? '');
            $error = (string) ($result['error_output'] ?? '');
            [$output, $normalized] = $this->finalizeWorkingDirState($sessionId, $server->id, $normalized, $output, $error);
            $finalMessage = $this->formatExecutionMessage($normalized, $output, $error, $approvedFromPrompt);

            $this->appendMessage($sessionId, 'ai', 'SSH', $finalMessage);
            if ($server) {
                $this->emitSse('cwd_update', ['cwd' => $this->getWorkingDir($sessionId, $server->id)]);
            }
            $this->emitSse('done', [
                'source' => 'SSH',
                'message' => $finalMessage,
                'cwd' => $server ? $this->getWorkingDir($sessionId, $server->id) : null,
                'status' => $error !== '' ? 'error' : 'ok',
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function close(): RedirectResponse
    {
        $sessionId = self::SINGLE_SESSION_ID;

        $full = $this->readSession($sessionId);
        $summary = $this->buildSummary($full);

        $this->writeSummary($sessionId, $summary);
        $this->appendMemoryFile(
            'summaries.md',
            '## '.now()->toIso8601String().PHP_EOL.$summary.PHP_EOL
        );

        $this->appendMessage($sessionId, 'system', 'SYSTEM', 'Session closed.');
        $this->clearPendingApproval($sessionId);

        return redirect()
            ->route('ai-terminal.index')
            ->with('success', 'AI session closed and summary generated.');
    }

    public function readFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'exists:servers,id'],
            'path' => ['required', 'string', 'max:2000'],
        ]);

        $server = Server::query()->findOrFail((int) $data['server_id']);
        $sessionId = self::SINGLE_SESSION_ID;
        $remotePath = $this->resolveRemotePath($sessionId, $server->id, (string) $data['path']);

        $command = 'if [ -f '.escapeshellarg($remotePath).' ]; then cat '.escapeshellarg($remotePath).'; else echo "__SP_FILE_NOT_FOUND__"; fi';

        try {
            $result = $this->ssh->executeOnServer($server, $command);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $output = (string) ($result['output'] ?? '');
        $error = (string) ($result['error_output'] ?? '');

        if (str_contains($output, '__SP_FILE_NOT_FOUND__')) {
            return response()->json([
                'ok' => false,
                'message' => 'File not found.',
                'path' => $remotePath,
            ], 404);
        }

        if (trim($error) !== '') {
            return response()->json([
                'ok' => false,
                'message' => $error,
                'path' => $remotePath,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'path' => $remotePath,
            'content' => $output,
            'cwd' => $this->getWorkingDir($sessionId, $server->id),
        ]);
    }

    public function saveFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'exists:servers,id'],
            'path' => ['required', 'string', 'max:2000'],
            'content' => ['required', 'string', 'max:1000000'],
        ]);

        $server = Server::query()->findOrFail((int) $data['server_id']);
        $sessionId = self::SINGLE_SESSION_ID;
        $remotePath = $this->resolveRemotePath($sessionId, $server->id, (string) $data['path']);
        $base64 = base64_encode((string) $data['content']);
        $dir = dirname($remotePath);

        $command = 'mkdir -p '.escapeshellarg($dir)
            .' && printf %s '.escapeshellarg($base64)
            .' | base64 -d > '.escapeshellarg($remotePath);

        try {
            $result = $this->ssh->executeOnServer($server, $command);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $error = (string) ($result['error_output'] ?? '');
        if (trim($error) !== '') {
            return response()->json([
                'ok' => false,
                'message' => $error,
                'path' => $remotePath,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'File saved.',
            'path' => $remotePath,
            'cwd' => $this->getWorkingDir($sessionId, $server->id),
        ]);
    }

    private function decide(string $sessionId, string $input, ?Server $server): array
    {
        $approvedFromPrompt = false;
        $approvalDecision = $this->resolveApprovalChoice($sessionId, $input);
        if ($approvalDecision !== null) {
            if (($approvalDecision['action'] ?? '') === 'approve_execute') {
                $input = (string) $approvalDecision['command'];
                $approvedFromPrompt = true;
            } else {
                return $approvalDecision;
            }
        }

        $normalized = $this->normalize($input);
        $resolvedCommand = $this->resolveNaturalCommand($normalized);
        if ($resolvedCommand !== null) {
            $normalized = $resolvedCommand;
            $mode = 'command';
        } else {
            $mode = $this->classifyMode($normalized);
        }

        if ($mode === 'chat') {
            $ai = $this->aiReply($input, $server);
            $aiMessage = trim((string) ($ai['message'] ?? ''));

            if ($server && $this->looksLikeCommand($aiMessage) && $this->commandRiskLevel($aiMessage) === 'safe') {
                try {
                    $commandToRun = $this->buildCommandWithWorkingDir($sessionId, $aiMessage, $server->id);
                    $ssh = $this->ssh->executeOnServer($server, $commandToRun);
                } catch (\Throwable $e) {
                    $ssh = [
                        'output' => '',
                        'error_output' => $e->getMessage(),
                    ];
                }

                $output = (string) ($ssh['output'] ?? '');
                $errorOutput = (string) ($ssh['error_output'] ?? '');
                [$output, $aiMessage] = $this->finalizeWorkingDirState($sessionId, $server->id, $aiMessage, $output, $errorOutput);
                $finalMessage = $this->formatExecutionMessage($aiMessage, $output, $errorOutput, false);

                $this->logSshResult($sessionId, $aiMessage, $ssh, $server->id);

                return [
                    'source' => 'ssh',
                    'message' => "AI command:\n{$aiMessage}\n\nOutput:\n{$finalMessage}",
                    'command_used' => $aiMessage,
                    'output' => $output,
                    'error_output' => $errorOutput,
                    'memory_id' => null,
                    'status' => $errorOutput !== '' ? 'error' : 'ok',
                    'action' => 'ai_command_executed',
                    'risk_level' => 'safe',
                ];
            }

            return $ai;
        }

        if ($mode === 'guide') {
            return $this->aiReply("Guide mode (no SSH). Explain step-by-step:\n".$input, $server);
        }

        if ($mode === 'command') {
            if (! $server) {
                return [
                    'source' => 'ai',
                    'message' => 'Select an SSH server first to run command.',
                    'command_used' => null,
                    'output' => '',
                    'error_output' => '',
                    'memory_id' => null,
                    'status' => 'ok',
                    'action' => 'chat',
                ];
            }

            $risk = $this->commandRiskLevel($normalized);

            if ($risk === 'blocked') {
                return $this->blockedResponse($normalized);
            }

            if ($risk === 'approval_required' && ! $approvedFromPrompt) {
                $this->savePendingApproval($sessionId, $normalized);
                return $this->approvalPromptResponse($normalized);
            }

            try {
                $commandToRun = $this->buildCommandWithWorkingDir($sessionId, $normalized, $server->id);
                $ssh = $this->ssh->executeOnServer($server, $commandToRun);
            } catch (\Throwable $e) {
                $ssh = [
                    'output' => '',
                    'error_output' => $e->getMessage(),
                ];
            }

            $output = (string) ($ssh['output'] ?? '');
            $errorOutput = (string) ($ssh['error_output'] ?? '');
            [$output, $normalized] = $this->finalizeWorkingDirState($sessionId, $server->id, $normalized, $output, $errorOutput);
            $finalMessage = $this->formatExecutionMessage($normalized, $output, $errorOutput, $approvedFromPrompt);

            $this->logSshResult($sessionId, $normalized, $ssh, $server->id);

            if ($errorOutput !== '') {
                if ($this->isWarningOnly($errorOutput)) {
                    return [
                        'source' => 'ssh',
                        'message' => $finalMessage,
                        'command_used' => $normalized,
                        'output' => $output,
                        'error_output' => $errorOutput,
                        'memory_id' => null,
                        'status' => 'ok',
                        'action' => 'executed_with_warnings',
                        'risk_level' => 'safe',
                    ];
                }

                $analysis = $this->analyzeErrorWithAi($normalized, $errorOutput, $output);
                $suggestion = (string) ($analysis['suggestion'] ?? 'Check logs and retry safely.');
                $fixCommand = trim((string) ($analysis['fix_command'] ?? ''));
                $fixSafe = (bool) ($analysis['is_safe'] ?? false);

                return [
                    'source' => 'ssh',
                    'message' => $finalMessage,
                    'command_used' => $normalized,
                    'output' => $output,
                    'error_output' => $errorOutput,
                    'memory_id' => null,
                    'status' => 'error',
                    'action' => 'resolve_error',
                    'risk_level' => 'safe',
                    'suggestion' => $suggestion,
                    'suggested_fix_command' => $fixCommand !== '' ? $fixCommand : null,
                    'suggested_fix_status' => ($fixCommand !== '' && $fixSafe) ? 'approval_required' : 'manual_review',
                ];
            }

            return [
                'source' => 'ssh',
                'message' => $finalMessage,
                'command_used' => $normalized,
                'output' => $output,
                'error_output' => $errorOutput,
                'memory_id' => null,
                'status' => $errorOutput !== '' ? 'error' : 'ok',
                'action' => 'executed',
                'risk_level' => 'safe',
            ];
        }

        return $this->aiReply($input, $server);
    }

    private function aiReply(string $input, ?Server $server): array
    {
        $message = $this->chatGpt->reply(
            $input,
            $server ? [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
            ] : []
        );

        $message = trim((string) $message);

        if ($message === '') {
            $message = 'Please tell me what you want to do. I can chat or run a safe SSH command.';
        }

        return [
            'source' => 'ai',
            'message' => $message,
            'command_used' => null,
            'output' => '',
            'error_output' => '',
            'memory_id' => null,
            'status' => 'ok',
            'action' => 'chat',
        ];
    }

    private function normalize(string $text): string
    {
        $text = trim($text);

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    private function classifyMode(string $input): string
    {
        $lcInput = mb_strtolower($input);

        if ($input === '') {
            return 'chat';
        }

        if (in_array($lcInput, [
            'hi',
            'hello',
            'hey',
            'thanks',
            'thank you',
            'ok',
            'okay',
            'yes',
            'no',
        ], true)) {
            return 'chat';
        }

        if ($this->looksLikeCommand($input)) {
            return 'command';
        }

        if ($this->looksLikeMultiCommand($input)) {
            return 'command';
        }

        if (preg_match('/\binstall\b/i', $lcInput) === 1) {
            return 'command';
        }

        if (preg_match('/\b(how to|guide|explain|steps|tutorial|what should i do)\b/i', $lcInput) === 1) {
            return 'guide';
        }

        return 'chat';
    }

    private function resolveNaturalCommand(string $input): ?string
    {
        $lcInput = mb_strtolower($input);

        if (preg_match('/^\s*(ls|cat|cd|pwd|rm|mv|cp|find|grep|sed)\b/i', $input) === 1) {
            if (trim($lcInput) === 'ls') {
                return 'ls -la';
            }

            return null;
        }

        if (preg_match('/^\s*(delete|remove)\s+([a-z0-9._-]+)\s*$/i', $lcInput, $m) === 1) {
            $target = trim((string) ($m[2] ?? ''));
            if ($target !== '') {
                return 'rm -f '.escapeshellarg($target);
            }
        }

        if (
            preg_match('/\bapp[_\s]?debug\b/i', $lcInput) === 1
            && preg_match('/\btrue\b/i', $lcInput) === 1
        ) {
            return "[ -f ServerPanel/.env ] && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' ServerPanel/.env || sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' .env";
        }

        if (
            preg_match('/\bapp[_\s]?debug\b/i', $lcInput) === 1
            && preg_match('/\bfalse\b/i', $lcInput) === 1
        ) {
            return "[ -f ServerPanel/.env ] && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' ServerPanel/.env || sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env";
        }

        if (
            str_contains($lcInput, 'check database user and password')
            || str_contains($lcInput, 'check db user and password')
            || str_contains($lcInput, 'test database connection')
            || str_contains($lcInput, 'test db connection')
            || str_contains($lcInput, 'check mysql login')
            || str_contains($lcInput, 'check database connection')
            || str_contains($lcInput, 'check db connection')
            || str_contains($lcInput, 'database connection check')
            || str_contains($lcInput, 'test mysql connection')
            || str_contains($lcInput, 'check mysql connection')
        ) {
            return "ENV_FILE='.env'; [ -f \"$ENV_FILE\" ] || ENV_FILE='ServerPanel/.env'; [ -f \"$ENV_FILE\" ] || { echo '.env not found'; exit 1; }; getv(){ grep -m1 \"^$1=\" \"$ENV_FILE\" | cut -d= -f2- | tr -d '\\r' | sed -e 's/^\"//' -e 's/\"$//'; }; DBH=\"$(getv DB_HOST)\"; DBP=\"$(getv DB_PORT)\"; DBN=\"$(getv DB_DATABASE)\"; DBU=\"$(getv DB_USERNAME)\"; DBW=\"$(getv DB_PASSWORD)\"; [ -n \"$DBH\" ] || DBH='127.0.0.1'; [ -n \"$DBP\" ] || DBP='3306'; [ -n \"$DBU\" ] || { echo 'DB_USERNAME missing in .env'; exit 1; }; [ -n \"$DBN\" ] || { echo 'DB_DATABASE missing in .env'; exit 1; }; mysql --protocol=TCP -h \"$DBH\" -P \"$DBP\" -u \"$DBU\" -p\"$DBW\" \"$DBN\" -e 'SELECT \"DB login ok\" AS status'";
        }

        if (
            str_contains($lcInput, '.env')
            && str_contains($lcInput, 'app debug')
            && str_contains($lcInput, 'true')
        ) {
            return "cd ~/ServerPanel && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' .env";
        }

        if (
            str_contains($lcInput, '.env')
            && str_contains($lcInput, 'app debug')
            && str_contains($lcInput, 'false')
        ) {
            return "cd ~/ServerPanel && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env";
        }

        if (
            str_contains($lcInput, 'go')
            && str_contains($lcInput, 'serverpanel')
            && str_contains($lcInput, '.env')
            && str_contains($lcInput, 'cat')
        ) {
            return 'cd ~/ServerPanel && cat .env';
        }

        if (
            str_contains($lcInput, 'serverpanel')
            && str_contains($lcInput, '.env')
            && str_contains($lcInput, 'cat')
        ) {
            return 'cat ServerPanel/.env';
        }

        if (
            str_contains($lcInput, 'installer path')
            || str_contains($lcInput, 'install path')
            || str_contains($lcInput, 'where is serverinstaller')
            || str_contains($lcInput, 'where serverinstaller')
            || str_contains($lcInput, 'find serverinstaller')
            || str_contains($lcInput, 'server installer path')
        ) {
            return 'command -v serverinstaller || which serverinstaller || whereis serverinstaller';
        }

        if (
            str_contains($lcInput, 'server info')
            || str_contains($lcInput, 'server information')
            || str_contains($lcInput, 'os details')
            || str_contains($lcInput, 'os info')
            || str_contains($lcInput, 'os version')
            || str_contains($lcInput, 'system info')
            || str_contains($lcInput, 'system information')
            || str_contains($lcInput, 'current server')
            || str_contains($lcInput, 'about server')
            || str_contains($lcInput, 'server details')
        ) {
            return 'lsb_release -a; uname -a; cat /etc/os-release';
        }

        if (
            str_contains($lcInput, 'check server installed')
            || str_contains($lcInput, 'check serverpanel installed')
            || str_contains($lcInput, 'serverpanel installed')
            || str_contains($lcInput, 'server installed')
        ) {
            return 'php -v; mysql --version; nginx -v; apache2 -v';
        }

        if (preg_match('/\b(current|check|show)\b.*\bphp\b.*\b(version)?\b/i', $lcInput) === 1 || str_contains($lcInput, 'php version')) {
            return 'php -v';
        }

        if (preg_match('/\b(current|check|show|get)\b.*\b(os|ubuntu|linux)\b.*\b(version|details|info)?\b/i', $lcInput) === 1) {
            return 'lsb_release -a; uname -a; cat /etc/os-release';
        }

        if (preg_match('/\b(current|check|show)\b.*\bmysql\b.*\b(status|version)?\b/i', $lcInput) === 1 || str_contains($lcInput, 'mysql version')) {
            return 'mysql --version';
        }

        if (preg_match('/\b(current|check|show)\b.*\b(memory|ram)\b/i', $lcInput) === 1) {
            return 'free -m';
        }

        if (preg_match('/\b(current|check|show)\b.*\b(disk|storage)\b/i', $lcInput) === 1) {
            return 'df -h';
        }

        if (preg_match('/\b(current|show|check)\b.*\b(location|directory|path|pwd)\b/i', $lcInput) === 1) {
            return 'pwd';
        }

        if (preg_match('/\binstall\b.*\bphp\b/i', $lcInput) === 1) {
            return 'sudo apt install php php-fpm -y';
        }

        if (preg_match('/\binstall\b.*\bnginx\b/i', $lcInput) === 1) {
            return 'sudo apt install nginx -y';
        }

        if (preg_match('/\binstall\b.*\bapache\b/i', $lcInput) === 1) {
            return 'sudo apt install apache2 -y';
        }

        if (preg_match('/\bupdate\b.*\bphp\b/i', $lcInput) === 1) {
            return 'sudo apt update';
        }

        return null;
    }

    private function looksLikeCommand(string $input): bool
    {
        $input = trim($input);

        if ($input === '') {
            return false;
        }

        $firstSegment = preg_split('/(&&|;|\r\n|\r|\n|\|)/', $input)[0] ?? $input;
        $firstSegment = trim($firstSegment);

        return preg_match('/^(sudo\s+)?(cd|ls|pwd|cat|sed|rm|mv|cp|mkdir|touch|php|composer|npm|node|systemctl|service|df|free|uptime|whoami|hostname|uname|lsb_release|journalctl|tail|grep|ps|top|du|find)\b/i', $firstSegment) === 1
            || in_array($input, [
                'check disk',
                'check php version',
                'check mysql status',
                'show memory',
                'server status',
            ], true);
    }

    private function looksLikeMultiCommand(string $input): bool
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return false;
        }

        if (! str_contains($trimmed, "\n") && ! str_contains($trimmed, ';')) {
            return false;
        }

        $parts = preg_split('/[;\r\n]+/', $trimmed) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! $this->looksLikeCommand($part)) {
                return false;
            }
        }

        return true;
    }

    private function commandRiskLevel(string $command): string
    {
        $cmd = trim(mb_strtolower($command));

        $blockedRules = [
            '/\brm\s+-rf\b/',
            '/\bmkfs\b/',
            '/\bdd\s+if=/',
            '/\bchmod\s+-r\s+777\s+\/\b/',
            '/\buserdel\b/',
            '/\bshutdown\b/',
            '/\breboot\b/',
            '/\bpoweroff\b/',
            '/\bufw\s+disable\b/',
            '/\biptables\s+-f\b/',
            '/\btruncate\b/',
            '/\bdrop\s+database\b/',
        ];

        foreach ($blockedRules as $rule) {
            if (preg_match($rule, $cmd) === 1) {
                return 'blocked';
            }
        }

        $approvalRules = [
            '/\brm\b/',
            '/\brm\s+-f\b/',
            '/\brm\s+-rf\b/',
            '/\bapt\s+install\b/',
            '/\bapt\s+upgrade\b/',
            '/\bapt-get\s+install\b/',
            '/\bapt-get\s+upgrade\b/',
            '/\bsystemctl\s+restart\b/',
            '/\bservice\s+\S+\s+restart\b/',
            '/\bcomposer\s+install\b/',
            '/\bnpm\s+install\b/',
            '/\bphp\s+artisan\s+migrate\b/',
            '/\bchmod\b/',
            '/\bchown\b/',
        ];

        foreach ($approvalRules as $rule) {
            if (preg_match($rule, $cmd) === 1) {
                return 'approval_required';
            }
        }

        return 'safe';
    }

    private function blockedResponse(string $command): array
    {
        return [
            'source' => 'ai',
            'message' => "Blocked dangerous command:\n\n{$command}",
            'command_used' => $command,
            'output' => '',
            'error_output' => '',
            'memory_id' => null,
            'status' => 'blocked',
            'action' => 'blocked',
            'risk_level' => 'blocked',
        ];
    }

    private function approvalPromptResponse(string $command): array
    {
        return [
            'source' => 'ai',
            'message' => "This command needs approval:\n\n{$command}\n\nReply with:\n1. Approve and run\n2. Cancel\n3. Show command again",
            'command_used' => $command,
            'output' => '',
            'error_output' => '',
            'memory_id' => null,
            'status' => 'approval_required',
            'action' => 'approval_required',
            'risk_level' => 'approval_required',
        ];
    }

    private function resolveApprovalChoice(string $sessionId, string $input): ?array
    {
        $choice = trim($input);
        if (! in_array($choice, [self::APPROVAL_YES, self::APPROVAL_NO, self::APPROVAL_SHOW], true)) {
            return null;
        }

        $pending = $this->getPendingApproval($sessionId);
        if ($pending === null || trim((string) ($pending['command'] ?? '')) === '') {
            return [
                'source' => 'ai',
                'message' => 'No pending approval command.',
                'status' => 'ok',
                'action' => 'chat',
            ];
        }

        $command = (string) $pending['command'];

        if ($choice === self::APPROVAL_SHOW) {
            return [
                'source' => 'ai',
                'message' => "Pending command:\n\n{$command}",
                'status' => 'approval_required',
                'action' => 'approval_show',
                'command_used' => $command,
            ];
        }

        if ($choice === self::APPROVAL_NO) {
            $this->clearPendingApproval($sessionId);
            return [
                'source' => 'ai',
                'message' => 'Command canceled.',
                'status' => 'ok',
                'action' => 'approval_canceled',
                'command_used' => $command,
            ];
        }

        $this->clearPendingApproval($sessionId);
        return [
            'action' => 'approve_execute',
            'command' => $command,
        ];
    }

    private function loadSessionById(string $id): array
    {
        $this->ensureStructure();

        $path = $this->sessionPath($id);
        $raw = is_file($path) ? (string) @file_get_contents($path) : '';
        $messages = $this->parseMessages($raw);

        return [
            'id' => $id,
            'title' => 'AI Terminal',
            'status' => str_contains($raw, 'Session closed.') ? 'closed' : 'active',
            'updated_at' => is_file($path)
                ? date(DATE_ATOM, filemtime($path) ?: time())
                : now()->toIso8601String(),
            'messages' => $messages,
        ];
    }

    private function parseMessages(string $raw): array
    {
        $parts = preg_split('/^##\s+/m', $raw) ?: [];
        $messages = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ($trimmed === '' || ! str_contains($trimmed, '|')) {
                continue;
            }

            $lines = preg_split('/\R/', $trimmed) ?: [];
            $header = array_shift($lines) ?? '';
            $message = trim(implode(PHP_EOL, $lines));
            $headerBits = array_map('trim', explode('|', $header));

            if (count($headerBits) < 3) {
                continue;
            }

            $messages[] = [
                'created_at' => $headerBits[0],
                'role' => strtolower($headerBits[1]),
                'source' => strtoupper($headerBits[2]),
                'message' => $message,
            ];
        }

        return $messages;
    }

    private function buildSummary(string $markdown): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $markdown))));
        $count = count($lines);
        $preview = implode(PHP_EOL, array_slice($lines, max(0, $count - 8)));

        return "Session completed with {$count} markdown lines.".PHP_EOL
            ."Key recent entries:".PHP_EOL
            .$preview;
    }

    private function ensureStructure(): void
    {
        foreach (['chats', 'analysis', 'memory', 'summaries', 'logs'] as $dir) {
            File::ensureDirectoryExists($this->basePath().DIRECTORY_SEPARATOR.$dir);
        }
    }

    private function basePath(): string
    {
        return storage_path(self::BASE_DIR);
    }

    private function sessionPath(string $sessionId): string
    {
        return $this->basePath()
            .DIRECTORY_SEPARATOR.'chats'
            .DIRECTORY_SEPARATOR.'session-'.$sessionId.'.md';
    }

    private function writeSession(string $sessionId): void
    {
        $this->ensureStructure();

        $path = $this->sessionPath($sessionId);

        if (! File::exists($path)) {
            File::put($path, '# AI Terminal Session '.$sessionId.PHP_EOL.PHP_EOL);
        }
    }

    private function appendMessage(string $sessionId, string $role, string $source, string $message): void
    {
        $this->writeSession($sessionId);

        File::append(
            $this->sessionPath($sessionId),
            '## '.now()->toIso8601String().' | '.$role.' | '.$source.PHP_EOL
            .$message.PHP_EOL.PHP_EOL
        );

        $this->refreshRecentSessionMemory($sessionId);
    }

    private function readSession(string $sessionId): string
    {
        $path = $this->sessionPath($sessionId);

        return File::exists($path) ? (string) File::get($path) : '';
    }

    private function writeSummary(string $sessionId, string $summary): void
    {
        $this->ensureStructure();

        File::put(
            $this->basePath()
            .DIRECTORY_SEPARATOR.'summaries'
            .DIRECTORY_SEPARATOR.'session-'.$sessionId.'.md',
            "# Session {$sessionId} Summary".PHP_EOL.PHP_EOL.$summary.PHP_EOL
        );
    }

    private function appendMemoryFile(string $name, string $content): void
    {
        $this->ensureStructure();

        File::append(
            $this->basePath().DIRECTORY_SEPARATOR.'memory'.DIRECTORY_SEPARATOR.$name,
            $content.PHP_EOL
        );
    }

    private function appendEngineLog(string $content): void
    {
        $this->ensureStructure();

        File::append(
            $this->basePath().DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'engine.log',
            '['.now()->toIso8601String().'] '.$content.PHP_EOL
        );
    }

    private function recentMemoryPath(string $sessionId): string
    {
        return $this->basePath()
            .DIRECTORY_SEPARATOR.'memory'
            .DIRECTORY_SEPARATOR.'session-'.$sessionId.'-last4.json';
    }

    private function refreshRecentSessionMemory(string $sessionId): void
    {
        $messages = $this->parseMessages($this->readSession($sessionId));
        $lastFour = array_slice($messages, -4);

        File::put(
            $this->recentMemoryPath($sessionId),
            json_encode([
                'session_id' => $sessionId,
                'updated_at' => now()->toIso8601String(),
                'count' => count($lastFour),
                'messages' => $lastFour,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function pendingApprovalPath(string $sessionId): string
    {
        return $this->basePath()
            .DIRECTORY_SEPARATOR.'chats'
            .DIRECTORY_SEPARATOR.'pending-'.$sessionId.'.json';
    }

    private function savePendingApproval(string $sessionId, string $command): void
    {
        $this->ensureStructure();

        File::put(
            $this->pendingApprovalPath($sessionId),
            json_encode([
                'command' => $command,
                'created_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    private function getPendingApproval(string $sessionId): ?array
    {
        $path = $this->pendingApprovalPath($sessionId);
        if (! File::exists($path)) {
            return null;
        }

        $parsed = json_decode((string) File::get($path), true);

        return is_array($parsed) ? $parsed : null;
    }

    private function clearPendingApproval(string $sessionId): void
    {
        $path = $this->pendingApprovalPath($sessionId);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private function logSshResult(string $sessionId, string $command, array $sshResult, int $serverId): void
    {
        $line = json_encode([
            'session_id' => $sessionId,
            'server_id' => $serverId,
            'command' => $command,
            'output' => (string) ($sshResult['output'] ?? ''),
            'error_output' => (string) ($sshResult['error_output'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);

        $this->appendEngineLog('ssh_result '.$line);
    }

    private function isWarningOnly(string $stderr): bool
    {
        $normalized = mb_strtolower(trim($stderr));
        if ($normalized === '') {
            return false;
        }

        $hasWarning = preg_match('/\b(warn|warning|deprecated|notice)\b/i', $stderr) === 1;
        $hasRealError = preg_match('/\b(fatal|error:|exception|traceback|failed|cannot|permission denied|not found|segmentation fault)\b/i', $stderr) === 1;

        return $hasWarning && ! $hasRealError;
    }

    /**
     * @return array{suggestion:string,fix_command:?string,is_safe:bool}
     */
    private function analyzeErrorWithAi(string $command, string $errorOutput, string $output): array
    {
        $raw = $this->chatGpt->reply(
            "Analyze this command failure and return ONLY JSON with keys: suggestion, fix_command, is_safe.\n".
            "Command: {$command}\n".
            "Error: {$errorOutput}\n".
            "Output: {$output}"
        );

        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $fix = trim((string) ($parsed['fix_command'] ?? ''));
            return [
                'suggestion' => trim((string) ($parsed['suggestion'] ?? 'Check logs and retry safely.')),
                'fix_command' => $fix !== '' ? $fix : null,
                'is_safe' => $fix !== '' ? $this->commandRiskLevel($fix) === 'safe' : false,
            ];
        }

        return [
            'suggestion' => trim($raw) !== '' ? trim($raw) : 'Check logs and retry safely.',
            'fix_command' => null,
            'is_safe' => false,
        ];
    }

    private function formatExecutionMessage(string $command, string $output, string $errorOutput, bool $approvedFromPrompt): string
    {
        $merged = trim($output.PHP_EOL.$errorOutput);
        $merged = (string) preg_replace('/^1\s*(\r?\n)+/m', '', $merged);
        $merged = trim($merged);

        if ($approvedFromPrompt && trim($errorOutput) === '') {
            if (preg_match('/^\s*rm\b/i', $command) === 1) {
                $parts = preg_split('/\s+/', trim($command)) ?: [];
                $target = end($parts);
                $target = is_string($target) ? trim($target, "\"'") : 'target';

                return "Approved and completed. Deleted: {$target}";
            }

            if ($merged === '' || $merged === '1') {
                return 'Approved and completed successfully.';
            }
        }

        if ($merged === '') {
            return 'Command executed, but no output returned.';
        }

        if ($merged === '1') {
            return 'Command completed successfully.';
        }

        return $merged;
    }

    private function buildCommandWithWorkingDir(string $sessionId, string $command, int $serverId): string
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return $trimmed;
        }

        $cwd = $this->getWorkingDir($sessionId, $serverId);
        $cwdExpr = $cwd === '~' ? '$HOME' : escapeshellarg($cwd);

        return "cd {$cwdExpr} && {$trimmed}; printf \"\\n__SP_CWD__:%s\\n\" \"\$PWD\"";
    }

    /**
     * @return array{0:string,1:string}
     */
    private function finalizeWorkingDirState(string $sessionId, int $serverId, string $originalCommand, string $output, string $errorOutput): array
    {
        $cleanOutput = $output;
        $newDir = '';
        $trimmedCommand = trim($originalCommand);
        $previousDir = $this->getWorkingDir($sessionId, $serverId);

        if (preg_match('/(?:^|\R)__SP_CWD__:(.+?)(?:\R|$)/', $output, $m) === 1) {
            $newDir = trim((string) ($m[1] ?? ''));
            $cleanOutput = (string) preg_replace('/(?:^|\R)__SP_CWD__:.+?(?:\R|$)/', PHP_EOL, $output);
            $cleanOutput = trim($cleanOutput);
        }

        if ($newDir === '' && trim($errorOutput) === '' && preg_match('/^(cd|pwd)\b/i', $trimmedCommand) === 1) {
            $lines = preg_split('/\R/', trim($cleanOutput)) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $candidate = trim((string) ($lines[$i] ?? ''));
                if ($candidate !== '' && str_starts_with($candidate, '/')) {
                    $newDir = $candidate;
                    break;
                }
            }
        }

        if ($newDir !== '' && str_starts_with($newDir, '/') && trim($errorOutput) === '') {
            $this->setWorkingDir($sessionId, $serverId, $newDir);
            if (
                preg_match('/^cd(?:\s+(.+))?$/i', $trimmedCommand) === 1
                && ($cleanOutput === '' || trim($cleanOutput) === '1')
            ) {
                $cleanOutput = "Current directory: {$newDir}";
            }
        }

        if ($newDir === '' && trim($errorOutput) === '' && preg_match('/^cd(?:\s+(.+))?$/i', $trimmedCommand, $m) === 1) {
            $target = trim((string) ($m[1] ?? ''));
            $guessed = $this->resolveCdTargetPath($previousDir, $target);
            if ($guessed !== '') {
                $this->setWorkingDir($sessionId, $serverId, $guessed);
                if ($cleanOutput === '' || trim($cleanOutput) === '1') {
                    $cleanOutput = "Current directory: {$guessed}";
                }
            }
        }

        return [$cleanOutput, $originalCommand];
    }

    private function resolveCdTargetPath(string $baseDir, string $target): string
    {
        $base = trim($baseDir);
        if ($base === '' || $base === '~') {
            $base = '/root';
        }

        $target = trim($target);
        if ($target === '' || $target === '~') {
            return '/root';
        }

        if (str_starts_with($target, '/')) {
            return $this->normalizeUnixPath($target);
        }

        if (str_starts_with($target, '~/')) {
            return $this->normalizeUnixPath('/root/'.substr($target, 2));
        }

        return $this->normalizeUnixPath(rtrim($base, '/').'/'.$target);
    }

    private function normalizeUnixPath(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($stack);
                continue;
            }

            $stack[] = $part;
        }

        return '/'.implode('/', $stack);
    }

    private function workingDirPath(string $sessionId, int $serverId): string
    {
        return $this->basePath()
            .DIRECTORY_SEPARATOR.'chats'
            .DIRECTORY_SEPARATOR."cwd-{$sessionId}-{$serverId}.txt";
    }

    private function getWorkingDir(string $sessionId, int $serverId): string
    {
        $path = $this->workingDirPath($sessionId, $serverId);
        if (! File::exists($path)) {
            return '/root';
        }

        $cwd = trim((string) File::get($path));

        return $cwd !== '' ? $cwd : '/root';
    }

    private function setWorkingDir(string $sessionId, int $serverId, string $cwd): void
    {
        $this->ensureStructure();
        File::put($this->workingDirPath($sessionId, $serverId), trim($cwd));
    }

    private function emitSse(string $event, array $payload): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        @ob_flush();
        flush();
    }
}
