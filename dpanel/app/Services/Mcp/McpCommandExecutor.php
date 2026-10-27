<?php

namespace App\Services\Mcp;

use App\Models\CommandJob;
use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\ServerPanel\CommandRunnerService;
use App\Services\ServerPanel\SshClientService;

/**
 * Runs a command on a managed server synchronously for an MCP tool call.
 * Every run is recorded as a CommandJob (tagged "mcp"), so it shows up in
 * the panel's command history and reports like any other command.
 */
class McpCommandExecutor
{
    public function __construct(
        private readonly McpCommandPolicy $policy,
        private readonly CommandRunnerService $commandRunner,
        private readonly SshClientService $sshClient,
    ) {}

    /**
     * Resolve the target server: an explicit id, or the only server when
     * just one is configured.
     */
    public function resolveServer(mixed $serverId): Server
    {
        if ($serverId !== null && $serverId !== '') {
            $server = Server::query()->find((int) $serverId);
            if (! $server) {
                throw new McpToolException("Server {$serverId} not found. Call list_servers to see the available ids.");
            }

            return $server;
        }

        $servers = Server::query()->limit(2)->get();

        if ($servers->count() === 1) {
            return $servers->first();
        }

        throw new McpToolException($servers->isEmpty()
            ? 'No servers are configured in dPanel yet. Add one under Servers first.'
            : 'More than one server is configured; pass server_id (see list_servers).');
    }

    /**
     * @param  bool  $trusted  The command was built by a tool from validated
     *                         input, so it skips the free-form policy check.
     * @return array{job_id: int, server: string, command: string, status: string, exit_code: int|null, output: string, error_output: string, truncated: bool}
     */
    public function run(Server $server, string $command, McpAccessToken $token, bool $trusted = false): array
    {
        $command = trim($command);

        if (! $trusted) {
            $decision = $this->policy->evaluate($command, $token);
            if (! $decision['allowed']) {
                throw new McpToolException("Command refused ({$decision['risk_level']}): {$decision['reason']}");
            }
        }

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

        @set_time_limit((int) config('serverpanel.command_timeout', 300) + 60);

        $this->commandRunner->markStarted($job);

        try {
            $result = $this->sshClient->executeOnServer($server, $command);
        } catch (\Throwable $exception) {
            $result = ['output' => '', 'error_output' => $exception->getMessage(), 'exit_code' => 1];
        }

        $job = $this->commandRunner->markFinished($job->fresh(), $result);

        return $this->present($job, $server);
    }

    /**
     * @return array{job_id: int, server: string, command: string, status: string, exit_code: int|null, output: string, error_output: string, truncated: bool}
     */
    private function present(CommandJob $job, Server $server): array
    {
        $limit = (int) config('mcp.max_output_chars', 30000);
        $output = (string) $job->output;
        $errorOutput = (string) $job->error_output;
        $truncated = mb_strlen($output) > $limit || mb_strlen($errorOutput) > $limit;

        return [
            'job_id' => $job->id,
            'server' => $server->name.' ('.$server->host.')',
            'command' => $job->command,
            'status' => $job->status,
            'exit_code' => $job->exit_code,
            // Keep the end of long output: errors are usually at the bottom.
            'output' => $truncated ? mb_substr($output, -$limit) : $output,
            'error_output' => $truncated ? mb_substr($errorOutput, -$limit) : $errorOutput,
            'truncated' => $truncated,
        ];
    }
}
