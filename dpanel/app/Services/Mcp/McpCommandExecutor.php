<?php

namespace App\Services\Mcp;

use App\Models\CommandJob;
use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\ActivityLogService;
use App\Services\ScriptExecutionGateway;
use App\Services\ServerPanel\CommandRunnerService;
use App\Services\ServerPanel\CommandSafetyService;
use App\Services\ServerPanel\SshClientService;

/**
 * Runs a command synchronously for an MCP tool call, either on a managed
 * server over SSH or on the panel host itself through drust.
 *
 * SSH runs are recorded as a CommandJob (tagged "mcp"), so they show up in
 * the panel's command history and reports. Panel-host runs have no server
 * row to hang a CommandJob on, so they go to the activity log instead.
 */
class McpCommandExecutor
{
    /** server_id value that targets the panel host. */
    public const LOCAL = 'local';

    public function __construct(
        private readonly McpCommandPolicy $policy,
        private readonly CommandRunnerService $commandRunner,
        private readonly SshClientService $sshClient,
        private readonly CommandSafetyService $safety,
        private readonly ScriptExecutionGateway $scripts,
        private readonly LocalHostAccess $localAccess,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Resolve the target: "local" for the panel host, a server id, or — when
     * none is given — the panel host if it allows MCP commands, else the only
     * configured server.
     *
     * @return Server|null null means the panel host.
     */
    public function resolveServer(mixed $serverId): ?Server
    {
        if ($serverId === self::LOCAL || $serverId === 0 || $serverId === '0') {
            if ($this->localAccess->level() === LocalHostAccess::OFF) {
                throw new McpToolException('MCP commands are off on the panel host. An admin can enable them with: sudo dpanel mcp local on [read|full]');
            }

            return null;
        }

        if ($serverId !== null && $serverId !== '') {
            $server = Server::query()->find((int) $serverId);
            if (! $server) {
                throw new McpToolException("Server {$serverId} not found. Call list_servers to see the available ids.");
            }

            return $server;
        }

        if ($this->localAccess->level() !== LocalHostAccess::OFF) {
            return null;
        }

        $servers = Server::query()->limit(2)->get();

        if ($servers->count() === 1) {
            return $servers->first();
        }

        throw new McpToolException($servers->isEmpty()
            ? 'No target to run commands on. Enable the panel host with "sudo dpanel mcp local on [read|full]", or add a server under Servers.'
            : 'More than one server is configured; pass server_id (see list_servers).');
    }

    /**
     * @param  bool  $trusted  The command was built by a tool from validated
     *                         input, so it skips the free-form policy check.
     * @return array<string, mixed>
     */
    public function run(?Server $server, string $command, McpAccessToken $token, bool $trusted = false): array
    {
        $command = trim($command);

        if (! $trusted) {
            $decision = $this->policy->evaluate($command, $token);
            if (! $decision['allowed']) {
                throw new McpToolException("Command refused ({$decision['risk_level']}): {$decision['reason']}");
            }
        }

        @set_time_limit((int) config('serverpanel.command_timeout', 300) + 60);

        return $server === null
            ? $this->runLocal($command, $token)
            : $this->runOverSsh($server, $command, $token, $trusted);
    }

    /**
     * @return array<string, mixed>
     */
    private function runOverSsh(Server $server, string $command, McpAccessToken $token, bool $trusted): array
    {
        $job = $this->commandRunner->createAndDispatch($server, $command, $token->user, [
            'dispatch' => false,
            'tags' => ['mcp', 'mcp-token:'.$token->id],
        ]);

        if ($job->status === 'blocked') {
            throw new McpToolException('Command refused (blocked): '.$job->risk_reason);
        }

        if ($job->risk_level === 'approval_required') {
            // Reaching here means the policy (or the tool that built the
            // command) already allowed it for this token: record that.
            $job->forceFill(['approved_by' => $token->user_id, 'approved_at' => now()])->save();
            $this->commandRunner->event($job, 'approved', ($trusted
                ? 'Command built by an MCP tool for token "'.$token->name.'".'
                : 'Command approved by full-access MCP token "'.$token->name.'".'), [
                    'approved_by' => $token->user_id,
                    'mcp_token_id' => $token->id,
                ]);
        }

        $this->commandRunner->markStarted($job);

        try {
            $result = $this->sshClient->executeOnServer($server, $command);
        } catch (\Throwable $exception) {
            $result = ['output' => '', 'error_output' => $exception->getMessage(), 'exit_code' => 1];
        }

        $job = $this->commandRunner->markFinished($job->fresh(), $result);

        return $this->present(
            $server->name.' ('.$server->host.')',
            $job->command,
            $job->status,
            $job->exit_code,
            (string) $job->output,
            (string) $job->error_output,
            ['job_id' => $job->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runLocal(string $command, McpAccessToken $token): array
    {
        $classification = $this->safety->classify($command);
        if ($classification['risk_level'] === 'blocked') {
            throw new McpToolException('Command refused (blocked): '.$classification['risk_reason']);
        }

        // A full token runs as root only where the host allows it; anything
        // else runs unprivileged (the script enforces this as well).
        $mode = $token->allowsMutations() && $this->localAccess->level() !== LocalHostAccess::READ ? 'full' : 'read';
        $timeout = max(1, (int) config('serverpanel.command_timeout', 300));

        $result = $this->scripts->withTimeout($timeout + 15)->execute('mcp-exec.sh', [$mode, (string) $timeout, $command]);

        if (! $result['ran'] || ! preg_match('/\n?__DPANEL_MCP_EXIT__=(\d+)\s*\z/', $result['output'], $match)) {
            throw new McpToolException('Could not run the command on the panel host: '.$this->scriptError($result['output']));
        }

        $exitCode = (int) $match[1];
        $output = (string) preg_replace('/\n?__DPANEL_MCP_EXIT__=\d+\s*\z/', '', $result['output']);

        $this->activityLog->log('mcp.local_command', $token, [
            'command' => $command,
            'mode' => $mode,
            'exit_code' => $exitCode,
            'output_tail' => mb_substr($output, -2000),
            'token' => $token->name,
        ]);

        return $this->present(
            'This panel host ('.(gethostname() ?: 'local').', '.($mode === 'full' ? 'root' : 'unprivileged').')',
            $command,
            $exitCode === 0 ? 'success' : 'failed',
            $exitCode,
            $output,
            '',
            [],
        );
    }

    private function scriptError(string $output): string
    {
        // drust answers a failed script with a non-200 JSON body whose message
        // reads "Failed: <path> failed with status N: <output>".
        $decoded = json_decode($output, true);
        if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
            $output = $decoded['message'];
        }
        $message = preg_match('/failed with status [^:]+: (.*)\z/s', $output, $match) ? $match[1] : $output;

        return trim(str_replace('[mcp-exec] ', '', $message)) ?: 'no response from drust.';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function present(string $target, string $command, string $status, ?int $exitCode, string $output, string $errorOutput, array $extra): array
    {
        $limit = (int) config('mcp.max_output_chars', 30000);
        $truncated = mb_strlen($output) > $limit || mb_strlen($errorOutput) > $limit;

        return [
            ...$extra,
            'server' => $target,
            'command' => $command,
            'status' => $status,
            'exit_code' => $exitCode,
            // Keep the end of long output: errors are usually at the bottom.
            'output' => $truncated ? mb_substr($output, -$limit) : $output,
            'error_output' => $truncated ? mb_substr($errorOutput, -$limit) : $errorOutput,
            'truncated' => $truncated,
        ];
    }
}
