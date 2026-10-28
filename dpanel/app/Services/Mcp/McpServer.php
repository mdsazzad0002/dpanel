<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Services\ActivityLogService;
use App\Services\Mcp\Contracts\McpTool;
use Illuminate\Support\Facades\Log;

/**
 * Model Context Protocol server (JSON-RPC 2.0 over Streamable HTTP, JSON
 * responses only). Implements the subset a tool server needs: initialize,
 * ping, tools/list and tools/call.
 *
 * @see https://modelcontextprotocol.io/specification
 */
class McpServer
{
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var array<int, class-string<McpTool>> */
    public const TOOLS = [
        Tools\PanelOverviewTool::class,
        Tools\ListServersTool::class,
        Tools\ListWebsitesTool::class,
        Tools\GetWebsiteTool::class,
        Tools\ListSslCertificatesTool::class,
        Tools\ServiceStatusTool::class,
        Tools\ReadServiceLogsTool::class,
        Tools\ReadLogFileTool::class,
        Tools\ListRecentCommandsTool::class,
        Tools\ListRecentActivityTool::class,
        Tools\FindWebsiteErrorsTool::class,
        Tools\ListDirectoryTool::class,
        Tools\ReadFileTool::class,
        Tools\SearchFilesTool::class,
        Tools\WriteFileTool::class,
        Tools\EditFileTool::class,
        Tools\FileOperationTool::class,
        Tools\RunCommandTool::class,
        Tools\RestartServiceTool::class,
    ];

    /** @var array<string, McpTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly ActivityLogService $activityLog) {}

    /**
     * Handle one decoded JSON-RPC message. Returns null for notifications,
     * which get no response.
     *
     * @return array<string, mixed>|null
     */
    public function handleMessage(mixed $message, McpAccessToken $token): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(is_array($message) ? ($message['id'] ?? null) : null, -32600, 'Invalid Request');
        }

        $isNotification = ! array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            // notifications/initialized, notifications/cancelled, … need no reply.
            return null;
        }

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->listTools($token)],
                'tools/call' => $this->callTool($params, $token),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => throw new McpProtocolException('Method not found: '.$message['method'], -32601),
            };
        } catch (McpProtocolException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : self::SUPPORTED_PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'dpanel',
                'title' => 'dPanel',
                'version' => $this->panelVersion(),
            ],
            'instructions' => implode("\n", [
                'You are connected to a dPanel web hosting control panel (websites, PHP/Node/Python/Docker apps, databases, mail, DNS, SSL).',
                'To troubleshoot: start with get_panel_overview, look up the site with get_website, then run find_website_errors and check get_service_status, read_service_logs or read_log_file.',
                'To change code or config: browse with list_directory and search_files, read_file before editing, then edit_file (or write_file). Every write keeps a backup and delete moves to a trash folder; the tools return where, so a fix can be undone.',
                'Explain the cause before changing anything. Prefer the smallest fix, and verify it afterwards.',
                'Every command you run is recorded in the panel. Read-only tokens cannot change server state.',
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTools(McpAccessToken $token): array
    {
        return array_values(array_map(fn (McpTool $tool) => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description()
                .(! $tool->readOnly() && ! $token->allowsMutations() ? ' (This token is read-only.)' : ''),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title' => $tool->title(),
                'readOnlyHint' => $tool->readOnly(),
                'destructiveHint' => ! $tool->readOnly(),
                'openWorldHint' => false,
            ],
        ], $this->tools()));
    }

    /**
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpAccessToken $token): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = $params['arguments'] ?? [];
        $tool = $this->tools()[$name] ?? null;

        if (! $tool) {
            throw new McpProtocolException("Unknown tool: {$name}", -32602);
        }
        if (! is_array($arguments)) {
            throw new McpProtocolException('arguments must be an object.', -32602);
        }

        ['data' => $data, 'is_error' => $isError] = $this->runTool($tool, $arguments, $token);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return [
            'content' => [['type' => 'text', 'text' => $json === false ? '{}' : $json]],
            'structuredContent' => $data,
            'isError' => $isError,
        ];
    }

    /**
     * Run one tool call; a failure comes back as data for the model, not as
     * an exception. Also used by the AI Gateway chat (GatewayChatTools).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, is_error: bool}
     */
    public function runTool(McpTool $tool, array $arguments, McpAccessToken $token): array
    {
        try {
            $data = $tool->handle($arguments, $token);
            $isError = false;
        } catch (McpToolException $e) {
            $data = ['error' => $e->getMessage()];
            $isError = true;
        } catch (\Throwable $e) {
            Log::error('MCP tool failed', ['tool' => $tool->name(), 'exception' => $e]);
            $data = ['error' => 'The tool failed: '.$e->getMessage()];
            $isError = true;
        }

        if (! $tool->readOnly()) {
            $this->activityLog->log('mcp.tool_called', $token, [
                'tool' => $tool->name(),
                'arguments' => $arguments,
                'token' => $token->name,
                'is_error' => $isError,
            ]);
        }

        return ['data' => $data, 'is_error' => $isError];
    }

    /**
     * @return array<string, McpTool>
     */
    public function tools(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::TOOLS as $class) {
                $tool = app($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    private function panelVersion(): string
    {
        $version = @file_get_contents(base_path('../VERSION'));

        return is_string($version) && trim($version) !== '' ? trim($version) : 'dev';
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
