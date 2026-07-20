<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommandJobRequest;
use App\Models\CommandJob;
use App\Models\Server;
use App\Models\SshCommandMemory;
use App\Services\ScriptPathResolver;
use App\Services\ServerPanel\Contracts\AiSuggestionProvider;
use App\Services\ServerPanel\CommandSafetyService;
use App\Services\ServerPanel\CommandRunnerService;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CommandJobController extends Controller
{
    public function __construct(private readonly AiSuggestionProvider $aiSuggestionProvider)
    {
    }

    public function index(?Server $server = null): Response
    {
        $jobs = CommandJob::query()
            ->with(['server:id,name,host', 'requestedBy:id,name,email', 'approvedBy:id,name,email'])
            ->when($server, fn ($query) => $query->where('server_id', $server->id))
            ->when(request('status'), fn ($query, $value) => $query->where('status', $value))
            ->when(request('risk'), fn ($query, $value) => $query->where('risk_level', $value))
            ->when(request('server_id'), fn ($query, $value) => $query->where('server_id', $value))
            ->when(request('q'), fn ($query, $value) => $query->where('command', 'like', '%'.$value.'%'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $selectedCommandId = request('selected');
        $selectedCommand = null;
        if ($selectedCommandId) {
            $selectedCommand = CommandJob::query()
                ->with([
                    'server',
                    'events',
                    'children' => fn ($query) => $query->latest(),
                    'requestedBy:id,name,email',
                    'approvedBy:id,name,email',
                ])
                ->find($selectedCommandId);
        }

        $upgradeLogs = CommandJob::query()
            ->with('server:id,name')
            ->when(request('server_id'), fn ($query, $value) => $query->where('server_id', $value))
            ->where(function ($query) {
                $query->where('command', 'like', '%apt upgrade%')
                    ->orWhere('command', 'like', '%apt-get upgrade%')
                    ->orWhere('command', 'like', '%dnf upgrade%')
                    ->orWhere('command', 'like', '%yum update%')
                    ->orWhere('command', 'like', '%dist-upgrade%')
                    ->orWhere('command', 'like', '%system upgrade%');
            })
            ->latest()
            ->limit(15)
            ->get(['id', 'server_id', 'command', 'status', 'created_at']);

        return Inertia::render('ServerPanel/Commands/Index', [
            'jobs' => $jobs,
            'filters' => array_merge(request()->only(['status', 'risk', 'server_id', 'q']), [
                'server_id' => request('server_id') ?: $server?->id,
            ]),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'selectedCommand' => $selectedCommand,
            'upgradeLogs' => $upgradeLogs,
            'markdownHistory' => $this->listMarkdownHistory((int) (request('server_id') ?: $server?->id ?: 0)),
            'canApprove' => $this->canApprove(),
        ]);
    }

    public function store(
        StoreCommandJobRequest $request,
        CommandRunnerService $commandRunner,
        SshClientService $sshClient,
        CommandSafetyService $commandSafety
    ): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $server = Server::query()->findOrFail($validated['server_id']);
        $rawInputCommand = trim((string) $validated['command']);
        $autoFixEnabled = (bool) ($validated['auto_fix'] ?? false);

        if ($this->isConversationalInput($rawInputCommand) || $this->isLikelyConversationalSentence($rawInputCommand)) {
            return $this->handleConversationalInput($rawInputCommand, $server, $sshClient);
        }

        if ($this->isAmbiguousInstallIntent($rawInputCommand)) {
            $this->logDecision([
                'input' => $rawInputCommand,
                'mode' => 'chat_clarification',
                'source' => 'rule',
                'resolved_command' => null,
                'confidence' => 0,
                'notes' => 'Ambiguous install intent, asked for clarification.',
            ]);
            return response()->json([
                'ok' => true,
                'chat_mode' => true,
                'message' => 'Please tell me exactly what to install (package/app). Example: install nginx, install docker, install php8.2-zip.',
                'system_info' => 'No command executed. Waiting for specific install target.',
            ]);
        }

        $resolution = $this->resolveNaturalLanguageCommandWithMeta($rawInputCommand);
        $resolvedCommand = $resolution['command'];
        $this->logDecision([
            'input' => $rawInputCommand,
            'mode' => 'command_resolution',
            'source' => $resolution['source'],
            'resolved_command' => $resolvedCommand !== '' ? $resolvedCommand : null,
            'confidence' => $resolution['confidence'],
            'notes' => $resolution['notes'],
        ]);
        if (trim($resolvedCommand) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Could not resolve this text to a safe executable command.',
            ], 422);
        }

        $job = $commandRunner->createAndDispatch(
            $server,
            $resolvedCommand,
            $request->user(),
            [
                'dispatch' => ! $request->expectsJson(),
                'task_id' => $validated['task_id'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'tags' => array_values(array_filter(array_merge(
                    (array) ($validated['tags'] ?? []),
                    $autoFixEnabled ? ['auto_fix_enabled'] : [],
                    $resolvedCommand !== $rawInputCommand ? ['ai_interpreted_input'] : [],
                ))),
            ],
        );

        // Realtime mode: execute immediately over AJAX and return instant feedback.
        if ($request->expectsJson()) {
            $this->startSessionMarkdown($job, $rawInputCommand, $resolvedCommand);

            if ($job->status === 'blocked') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Command blocked for safety.',
                    'job' => $job->fresh(['events', 'children', 'server']),
                ], 422);
            }

            $commandRunner->markStarted($job->fresh());

            try {
                $result = $sshClient->executeOnServerStreaming(
                    $server,
                    $job->command,
                    function (string $line) use ($commandRunner, $job): void {
                        $this->appendSessionMarkdown($job, 'Server', $line);
                        $commandRunner->event($job, 'output', $line, [
                            'stream_type' => 'output_line',
                        ]);

                        $step = $this->parseInstallerStepFromLine($line);
                        if ($step !== null) {
                            $commandRunner->event($job, 'output', $step['label'].' => '.$step['status'], [
                                'stream_type' => 'step_status',
                                'step_no' => $step['step_no'],
                                'label' => $step['label'],
                                'status' => $step['status'],
                            ]);
                        }
                    }
                );
            } catch (\Throwable $exception) {
                $result = [
                    'output' => '',
                    'error_output' => $exception->getMessage(),
                    'exit_code' => 1,
                ];
            }

            $finished = $commandRunner->markFinished($job->fresh(), $result)->fresh([
                'server',
                'events',
                'children' => fn ($query) => $query->latest(),
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
            ]);

            $this->appendSessionMarkdown($finished, 'System', 'Exit: '.($finished->exit_code ?? '-'));
            if (! empty($finished->error_output)) {
                $this->appendSessionMarkdown($finished, 'STDERR', (string) $finished->error_output);
            }

            if ($autoFixEnabled && $finished->status === 'failed') {
                $this->runAutoFixTaskFlow($finished, $server, $commandRunner, $sshClient, $commandSafety);
                $finished = $finished->fresh([
                    'server',
                    'events',
                    'children' => fn ($query) => $query->latest(),
                    'requestedBy:id,name,email',
                    'approvedBy:id,name,email',
                ]);
            }

            return response()->json([
                'ok' => true,
                'message' => $resolvedCommand !== $rawInputCommand
                    ? 'Input interpreted and executed in realtime.'
                    : 'Command executed in realtime.',
                'job' => $finished,
            ]);
        }

        return redirect()->route('commands.show', $job)->with('success', 'Command submitted with status: '.$job->status);
    }

    public function show(CommandJob $commandJob): RedirectResponse
    {
        return redirect()->route('commands.index', [
            'server_id' => $commandJob->server_id,
            'selected' => $commandJob->id,
        ]);
    }

    public function live(CommandJob $commandJob): JsonResponse
    {
        $job = CommandJob::query()
            ->with([
                'server',
                'events',
                'children' => fn ($query) => $query->latest(),
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
            ])
            ->findOrFail($commandJob->id);

        return response()->json([
            'ok' => true,
            'job' => $job,
        ]);
    }

    public function approve(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        abort_unless($this->canApprove(), 403, 'Admin approval required.');
        if ($commandJob->status !== 'pending_approval') {
            return back()->with('error', 'Only pending commands can be approved.');
        }

        $commandRunner->approve($commandJob, request()->user());

        return back()->with('success', 'Command approved and queued.');
    }

    public function cancel(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        if (! in_array($commandJob->status, ['draft', 'pending_approval', 'queued'], true)) {
            return back()->with('error', 'This command can no longer be cancelled.');
        }

        $commandRunner->cancel($commandJob);

        return back()->with('success', 'Command cancelled.');
    }

    public function retry(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        $newJob = $commandRunner->createAndDispatch(
            $commandJob->server,
            $commandJob->command,
            request()->user(),
            [
                'parent_id' => $commandJob->id,
                'task_id' => $commandJob->task_id,
                'tags' => array_merge((array) $commandJob->tags, ['retry']),
            ],
        );
        $newJob->increment('retry_count');

        $newJob->events()->create([
            'type' => 'retried',
            'message' => 'Retry created from command #'.$commandJob->id,
            'meta' => ['source_command_job_id' => $commandJob->id],
        ]);

        return redirect()->route('commands.show', $newJob)->with('success', 'Retry queued with status: '.$newJob->status);
    }

    public function runSuggestedFix(CommandJob $commandJob, CommandRunnerService $commandRunner): RedirectResponse
    {
        if ($commandJob->risk_level === 'blocked') {
            return back()->with('error', 'Blocked fix command cannot run.');
        }

        if ($commandJob->risk_level === 'approval_required' && ! $this->canApprove()) {
            return back()->with('error', 'Admin approval required for this fix.');
        }

        if ($commandJob->risk_level === 'approval_required') {
            $commandRunner->approve($commandJob, request()->user());
        } else {
            $commandRunner->dispatchExecution($commandJob);
        }

        return back()->with('success', 'Suggested fix queued.');
    }

    public function close(CommandJob $commandJob): RedirectResponse
    {
        $job = CommandJob::query()->with(['server', 'events'])->findOrFail($commandJob->id);
        $relatedIds = CommandJob::query()
            ->where('id', $job->id)
            ->orWhere('parent_id', $job->id)
            ->pluck('id')
            ->all();
        $isCompleteSuccess = $job->status === 'success' && ((int) ($job->exit_code ?? 1) === 0);

        $ai = $this->aiSuggestionProvider->suggest([
            'server' => [
                'id' => $job->server?->id,
                'name' => $job->server?->name,
                'host' => $job->server?->host,
            ],
            'command' => (string) $job->command,
            'output' => (string) ($job->output ?? ''),
            'error_output' => (string) ($job->error_output ?? ''),
            'error_signature' => $this->extractErrorSignature($job),
            'memory_hint' => null,
        ]);

        $job->forceFill([
            'ai_summary' => trim((string) ($ai['problem_summary'] ?? $ai['problem_title'] ?? '')),
            'ai_fix_suggestion' => trim((string) ($ai['suggested_fix'] ?? '')),
            'ai_fix_commands' => array_values(array_filter((array) ($ai['fix_commands'] ?? []), fn ($c) => is_string($c) && trim($c) !== '')),
        ])->save();

        if ($isCompleteSuccess) {
            $this->storeCommandMemory($job->fresh());
            $this->appendSessionMarkdown($job, 'AI Summary', (string) ($job->ai_summary ?? ''));
            $this->appendSessionMarkdown($job, 'AI Suggested Fix', (string) ($job->ai_fix_suggestion ?? ''));
            $this->appendSessionMarkdown($job, 'AI Fix Commands', implode(PHP_EOL, (array) ($job->ai_fix_commands ?? [])));
            $this->archiveSessionMarkdown($job);
        }
        $this->deleteSessionMarkdown($job);
        $serverId = $job->server_id;

        // Remove DB associations for command details; markdown stays as the single history source.
        DB::table('server_task_steps')->whereIn('command_job_id', $relatedIds)->update(['command_job_id' => null]);
        if (Schema::hasTable('ai_error_resolutions')) {
            DB::table('ai_error_resolutions')->whereIn('command_job_id', $relatedIds)->update(['command_job_id' => null]);
        }
        DB::table('command_events')->whereIn('command_job_id', $relatedIds)->delete();
        DB::table('command_jobs')->whereIn('id', $relatedIds)->delete();

        return redirect()->route('commands.index', array_filter([
            'server_id' => $serverId,
        ]))->with('success', $isCompleteSuccess
            ? 'Chat closed. Completed command kept in Markdown + memory; removed from database.'
            : 'Chat closed. Incomplete/failed command was not kept; removed from database.');
    }

    public function destroy(CommandJob $commandJob): RedirectResponse
    {
        $serverId = $commandJob->server_id;
        $selectedId = request('selected');

        $commandJob->delete();

        $nextSelected = ((string) $selectedId === (string) $commandJob->id) ? '' : $selectedId;

        return redirect()->route('commands.index', array_filter([
            'server_id' => request('server_id') ?: $serverId,
            'status' => request('status'),
            'risk' => request('risk'),
            'q' => request('q'),
            'selected' => $nextSelected,
        ], fn ($value) => $value !== null && $value !== ''))
            ->with('success', 'Command log deleted.');
    }

    public function downloadReport(CommandJob $commandJob)
    {
        abort_if(! $commandJob->report_path, 404, 'Report not generated yet.');
        abort_if(! Storage::disk('local')->exists($commandJob->report_path), 404, 'Report not found.');

        return Storage::disk('local')->download($commandJob->report_path, 'command-'.$commandJob->uuid.'.txt');
    }

    private function canApprove(): bool
    {
        return (bool) request()->user()?->hasRole('admin');
    }

    private function resolveNaturalLanguageCommand(string $input): string
    {
        return $this->resolveNaturalLanguageCommandWithMeta($input)['command'];
    }

    /**
     * @return array{command:string,source:string,confidence:float,notes:string}
     */
    private function resolveNaturalLanguageCommandWithMeta(string $input): array
    {
        $trimmed = trim($input);
        if ($this->isConversationalInput($trimmed)) {
            return ['command' => '', 'source' => 'conversation', 'confidence' => 1.0, 'notes' => 'Conversational input'];
        }

        if ($trimmed === '' || $this->looksLikeShellCommand($trimmed)) {
            return ['command' => $trimmed, 'source' => 'direct_shell', 'confidence' => 1.0, 'notes' => 'Direct executable command'];
        }

        $bestScore = 0.0;
        $memoryCommand = $this->findMemoryCommandBySimilarity($trimmed, 0.60, $bestScore);
        if ($memoryCommand !== null) {
            return ['command' => $memoryCommand, 'source' => 'memory', 'confidence' => $bestScore, 'notes' => 'Matched existing memory'];
        }

        $ai = $this->aiSuggestionProvider->suggest([
            'server' => [],
            'command' => $trimmed,
            'output' => '',
            'error_output' => 'User intent command. Convert to executable Linux shell command.',
            'error_signature' => 'natural_language_command',
            'memory_hint' => null,
        ]);

        $candidate = trim((string) (($ai['fix_commands'][0] ?? '') ?: ''));
        if ($candidate !== '' && $this->looksLikeShellCommand($candidate) && ! str_starts_with($candidate, '#')) {
            return ['command' => $candidate, 'source' => 'ai', 'confidence' => 0.65, 'notes' => 'Resolved by AI decision maker'];
        }

        // Safe built-in intent mapping fallback when AI/memory does not provide a valid shell command.
        $mapped = $this->mapIntentToDefaultCommand($trimmed);
        if ($mapped !== null) {
            return ['command' => $mapped, 'source' => 'fallback_mapping', 'confidence' => 0.55, 'notes' => 'Resolved by fallback mapping'];
        }

        return ['command' => '', 'source' => 'unresolved', 'confidence' => 0.0, 'notes' => 'No safe executable output from memory/AI'];
    }

    private function looksLikeShellCommand(string $value): bool
    {
        return preg_match('/[;&|`$(){}]|^(\.?\/|sudo\s+|apt\s+|apt-get\s+|dnf\s+|yum\s+|systemctl\s+|service\s+|bash\s+|sh\s+|php\s+|npm\s+|composer\s+|git\s+|ls\s+|cd\s+|cat\s+|echo\s+)/i', $value) === 1;
    }

    private function mapIntentToDefaultCommand(string $intent): ?string
    {
        $text = strtolower(trim($intent));
        $words = preg_split('/\s+/', $text) ?: [];
        $hasFresh = in_array('fresh', $words, true);
        $hasInstall = in_array('install', $words, true) || str_contains($text, 'installation');
        $hasServer = in_array('server', $words, true);
        $hasComplete = in_array('complete', $words, true) || in_array('full', $words, true);
        $hasUpdate = in_array('update', $words, true) || in_array('upgrade', $words, true);
        $hasHealth = str_contains($text, 'health') || str_contains($text, 'check');

        if (($hasFresh && $hasInstall && $hasServer) || str_contains($text, 'fresh install') || str_contains($text, 'install server')) {
            return $this->buildInstallerCommand('install.sh', 'fresh install this server');
        }

        if (($hasComplete && $hasInstall && $hasServer) || str_contains($text, 'complete install')) {
            return $this->buildInstallerCommand('install.sh', 'complete install server');
        }

        if ($hasUpdate && $hasServer) {
            return $this->buildInstallerCommand('update.sh');
        }

        if ($hasHealth && $hasServer) {
            return $this->buildInstallerCommand('health-check.sh');
        }

        return null;
    }

    private function storeCommandMemory(CommandJob $job): void
    {
        $normalized = strtolower(trim((string) ($job->normalized_command ?: $job->command)));
        if ($normalized === '') {
            return;
        }

        $memory = SshCommandMemory::query()->firstOrCreate(
            ['command' => $normalized],
            [
                'title' => mb_substr($normalized, 0, 120),
                'context' => 'Auto-captured from realtime terminal command execution',
                'success_output_sample' => null,
                'error_signature' => null,
                'category' => 'realtime_command',
                'tags' => ['auto', 'realtime'],
                'success_count' => 0,
                'fail_count' => 0,
            ],
        );

        if ($job->status === 'success') {
            $memory->increment('success_count');
            if (! empty($job->output)) {
                $memory->forceFill([
                    'success_output_sample' => mb_substr((string) $job->output, 0, 5000),
                ])->save();
            }
        } else {
            $memory->increment('fail_count');
        }

        $memory->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * @return array{step_no:int,label:string,status:string}|null
     */
    private function parseInstallerStepFromLine(string $line): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^0?(\d{1,2})\s+(.+?)\s+(running|pending|success|failed)$/i', $normalized, $matches) !== 1) {
            return null;
        }

        return [
            'step_no' => (int) $matches[1],
            'label' => trim($matches[2]),
            'status' => strtolower(trim($matches[3])),
        ];
    }

    private function sessionMarkdownPath(CommandJob $job): string
    {
        return 'serverpanel/chat_sessions/command-'.$job->id.'.md';
    }

    private function startSessionMarkdown(CommandJob $job, string $rawInput, string $resolvedCommand): void
    {
        $content = '# Command Chat Session'.PHP_EOL.PHP_EOL;
        $content .= '- Command Job ID: '.$job->id.PHP_EOL;
        $content .= '- Created At: '.now()->toIso8601String().PHP_EOL.PHP_EOL;
        $content .= '## User Input'.PHP_EOL.$rawInput.PHP_EOL.PHP_EOL;
        $content .= '## Resolved Command'.PHP_EOL.$resolvedCommand.PHP_EOL.PHP_EOL;

        Storage::disk('local')->put($this->sessionMarkdownPath($job), $content);
    }

    private function appendSessionMarkdown(CommandJob $job, string $title, string $body): void
    {
        if (trim($body) === '') {
            return;
        }

        Storage::disk('local')->append(
            $this->sessionMarkdownPath($job),
            '## '.$title.PHP_EOL.trim($body).PHP_EOL
        );
    }

    private function deleteSessionMarkdown(CommandJob $job): void
    {
        $path = $this->sessionMarkdownPath($job);
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    private function archiveSessionMarkdown(CommandJob $job): void
    {
        $source = $this->sessionMarkdownPath($job);
        if (! Storage::disk('local')->exists($source)) {
            return;
        }

        $stamp = now()->format('Ymd_His');
        $target = 'serverpanel/history/server-'.$job->server_id.'/command-'.$job->id.'-'.$stamp.'.md';
        Storage::disk('local')->copy($source, $target);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listMarkdownHistory(int $serverId = 0): array
    {
        $base = 'serverpanel/history';
        $files = Storage::disk('local')->allFiles($base);
        rsort($files);

        $items = [];
        foreach ($files as $file) {
            if (! str_ends_with($file, '.md')) {
                continue;
            }
            if ($serverId > 0 && ! str_contains($file, '/server-'.$serverId.'/')) {
                continue;
            }

            $content = (string) Storage::disk('local')->get($file);
            preg_match('/## User Input\s+(.+?)(?:\R##|\z)/s', $content, $inputMatch);
            preg_match('/## AI Summary\s+(.+?)(?:\R##|\z)/s', $content, $summaryMatch);
            $items[] = [
                'path' => $file,
                'title' => basename($file),
                'user_input' => trim((string) ($inputMatch[1] ?? '')),
                'ai_summary' => trim((string) ($summaryMatch[1] ?? '')),
                'updated_at' => date('c', Storage::disk('local')->lastModified($file)),
            ];
            if (count($items) >= 30) {
                break;
            }
        }

        return $items;
    }

    private function extractErrorSignature(CommandJob $job): string
    {
        $stderr = trim((string) ($job->error_output ?? ''));
        if ($stderr === '') {
            return $job->status === 'success' ? 'success' : 'unknown_error';
        }

        $line = Str::of($stderr)->before(PHP_EOL)->trim()->toString();
        return Str::limit($line !== '' ? $line : $stderr, 120, '');
    }

    private function isConversationalInput(string $input): bool
    {
        $text = strtolower(trim($input));
        if ($text === '') {
            return false;
        }

        return in_array($text, [
            'hi', 'hello', 'hey', 'ok', 'okay', 'thanks', 'thank you',
            'how are you', 'good morning', 'good night',
        ], true);
    }

    private function isLikelyConversationalSentence(string $input): bool
    {
        $text = strtolower(trim($input));
        if ($text === '' || $this->looksLikeShellCommand($text)) {
            return false;
        }

        $startsLikeChat = preg_match('/^(hi|hello|hey|thanks|thank you)\b/i', $text) === 1;
        $hasChatPhrase = str_contains($text, 'i want')
            || str_contains($text, 'i need')
            || str_contains($text, 'can you')
            || str_contains($text, 'please')
            || str_contains($text, 'try something');

        $hasExplicitOpsIntent = str_contains($text, 'fresh install')
            || str_contains($text, 'complete install')
            || str_contains($text, 'install server')
            || str_contains($text, 'update server')
            || str_contains($text, 'health check')
            || preg_match('/\binstall\s+[a-z0-9._:+-]{2,}\b/i', $text) === 1;

        if ($hasExplicitOpsIntent) {
            return false;
        }

        return $startsLikeChat || $hasChatPhrase;
    }

    private function isAmbiguousInstallIntent(string $input): bool
    {
        $text = strtolower(trim($input));
        if ($text === '') {
            return false;
        }

        $hasInstallIntent = str_contains($text, 'install')
            || str_contains($text, 'setup')
            || str_contains($text, 'need to install');

        if (! $hasInstallIntent) {
            return false;
        }

        // If text already has explicit executable command, let normal flow continue.
        if ($this->looksLikeShellCommand($text)) {
            return false;
        }

        // Known explicit intents we already map safely.
        $knownIntent = str_contains($text, 'fresh install')
            || str_contains($text, 'complete install')
            || str_contains($text, 'install server')
            || str_contains($text, 'health check')
            || str_contains($text, 'update server');

        if ($knownIntent) {
            return false;
        }

        // If no specific package/tool token appears after install-like words, treat as ambiguous.
        return preg_match('/\binstall\b\s+([a-z0-9._:+-]{2,})/i', $text) !== 1;
    }

    private function handleConversationalInput(string $input, Server $server, SshClientService $sshClient): JsonResponse
    {
        $greeting = 'Hello sir, how can I help you? I can install server, run upgrade, and check health. '
            .'Please tell me one task, for example: "fresh install server", "update server", or "health check server".';

        $memory = SshCommandMemory::query()
            ->where('category', 'greeting')
            ->orderByDesc('success_count')
            ->first();
        $memoryGreeting = trim((string) ($memory?->success_output_sample ?? ''));
        if ($memoryGreeting !== '') {
            $greeting = $memoryGreeting;
        }

        $probe = $sshClient->testConnection($server);
        $systemInfo = trim((string) ($probe['output'] ?? ''));
        if ($systemInfo === '') {
            $systemInfo = 'System information unavailable right now.';
        }

        return response()->json([
            'ok' => true,
            'chat_mode' => true,
            'message' => $greeting,
            'system_info' => $systemInfo,
        ]);
    }

    private function buildInstallerCommand(string $script, ?string $arg = null): string
    {
        $argPart = $arg !== null ? ' '.escapeshellarg($arg) : '';
        $searchPaths = ScriptPathResolver::repositorySearchPaths();

        $paths = [];
        foreach ($searchPaths as $root) {
            $paths[] = rtrim((string) $root, '/').'/'.$script;
        }

        return "bash -lc 'SCRIPT=\"\"; ".
            "for p in ".implode(' ', array_map(static fn ($path) => escapeshellarg($path), $paths))."; ".
            "do [ -f \"$p\" ] && SCRIPT=\"$p\" && break; done; ".
            "if [ -z \"$SCRIPT\" ]; then echo \"Installer script not found: {$script}\" >&2; exit 127; fi; ".
            "bash \"$SCRIPT\"{$argPart}'";
    }

    private function findMemoryCommandBySimilarity(string $input, float $threshold = 0.60, ?float &$bestScoreOut = null): ?string
    {
        $needle = mb_strtolower(trim($input));
        if ($needle === '') {
            return null;
        }

        $bestScore = 0.0;
        $bestCommand = null;

        $candidates = SshCommandMemory::query()
            ->orderByDesc('success_count')
            ->limit(200)
            ->get(['title', 'context', 'command']);

        foreach ($candidates as $memory) {
            $haystack = mb_strtolower(trim(implode(' ', array_filter([
                (string) $memory->title,
                (string) $memory->context,
                (string) $memory->command,
            ]))));

            if ($haystack === '') {
                continue;
            }

            $score = $this->textSimilarity($needle, $haystack);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCommand = trim((string) $memory->command);
            }
        }

        if ($bestScore >= $threshold && $bestCommand !== '') {
            $bestScoreOut = $bestScore;
            return $bestCommand;
        }

        $bestScoreOut = $bestScore;
        return null;
    }

    private function textSimilarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);
        return max(0.0, min(1.0, ((float) $percent) / 100));
    }

    /**
     * @param array{input:string,mode:string,source:string,resolved_command:?string,confidence:float|int,notes:string} $payload
     */
    private function logDecision(array $payload): void
    {
        $dir = 'serverpanel/decision_logs';
        $date = now()->format('Y-m-d');
        $path = $dir.'/decision-'.$date.'.md';

        $entry = '## '.now()->toIso8601String().PHP_EOL
            .'input: '.$payload['input'].PHP_EOL
            .'mode: '.$payload['mode'].PHP_EOL
            .'source: '.$payload['source'].PHP_EOL
            .'confidence: '.number_format((float) $payload['confidence'], 2).PHP_EOL
            .'resolved_command: '.($payload['resolved_command'] ?? '(none)').PHP_EOL
            .'notes: '.$payload['notes'].PHP_EOL;

        Storage::disk('local')->append($path, $entry);
    }

    private function runAutoFixTaskFlow(
        CommandJob $parentJob,
        Server $server,
        CommandRunnerService $commandRunner,
        SshClientService $sshClient,
        CommandSafetyService $commandSafety
    ): void {
        $ai = $this->aiSuggestionProvider->suggest([
            'server' => ['id' => $server->id, 'name' => $server->name, 'host' => $server->host],
            'command' => (string) $parentJob->command,
            'output' => (string) ($parentJob->output ?? ''),
            'error_output' => (string) ($parentJob->error_output ?? ''),
            'error_signature' => $this->extractErrorSignature($parentJob),
            'memory_hint' => null,
        ]);

        $fixCommands = array_values(array_filter((array) ($ai['fix_commands'] ?? []), fn ($c) => is_string($c) && trim($c) !== ''));
        if ($fixCommands === []) {
            $commandRunner->event($parentJob, 'output', 'Auto-fix enabled but AI returned no fix commands.', ['stream_type' => 'auto_fix']);
            return;
        }

        foreach ($fixCommands as $index => $fixCommand) {
            $fixCommand = trim($fixCommand);
            if ($fixCommand === '') {
                continue;
            }
            $classification = $commandSafety->classify($fixCommand);
            if (($classification['risk_level'] ?? 'blocked') !== 'safe') {
                $commandRunner->event($parentJob, 'output', 'Auto-fix skipped non-safe command: '.$fixCommand, [
                    'stream_type' => 'auto_fix',
                    'risk_level' => $classification['risk_level'] ?? 'unknown',
                    'step' => $index + 1,
                ]);
                continue;
            }

            $child = $commandRunner->createAndDispatch(
                $server,
                $fixCommand,
                request()->user(),
                [
                    'dispatch' => false,
                    'parent_id' => $parentJob->id,
                    'tags' => ['auto_fix', 'task_flow'],
                ],
            );

            $commandRunner->markStarted($child->fresh());
            try {
                $result = $sshClient->executeOnServer($server, $child->command);
            } catch (\Throwable $exception) {
                $result = [
                    'output' => '',
                    'error_output' => $exception->getMessage(),
                    'exit_code' => 1,
                ];
            }
            $done = $commandRunner->markFinished($child->fresh(), $result);
            $commandRunner->event($parentJob, 'output', 'Auto-fix step '.($index + 1).' executed: '.$fixCommand.' => '.$done->status, [
                'stream_type' => 'auto_fix',
                'child_job_id' => $done->id,
                'step' => $index + 1,
            ]);
        }
    }
}
