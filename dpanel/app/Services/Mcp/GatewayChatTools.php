<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Models\User;

/**
 * Lets the AI Gateway chat call the MCP tools itself: the model behind the
 * chat gets them as function-calling tools, and each call runs through
 * McpServer exactly as it would for a remote MCP client.
 *
 * The chat acts through an MCP token of its own per admin and access level,
 * listed on the MCP Access page like any other, so its last use shows there
 * and disabling it there turns the chat's tools off. Its secret is thrown
 * away, so it only works from inside the panel.
 */
class GatewayChatTools
{
    public const MODE_OFF = 'off';

    public const MODES = [self::MODE_OFF, McpAccessToken::SCOPE_READ, McpAccessToken::SCOPE_FULL];

    /** Tool output handed back to the model is cut to this many characters. */
    private const MAX_RESULT_CHARS = 40000;

    public function __construct(private readonly McpServer $server) {}

    /** Same rule as the remote endpoint: active admins only. */
    public static function allowedFor(?User $user): bool
    {
        return $user !== null
            && ! $user->is_suspended
            && $user->hasRole('admin')
            && (bool) config('mcp.enabled', true);
    }

    public static function tokenName(string $scope): string
    {
        return $scope === McpAccessToken::SCOPE_FULL ? 'AI Gateway Chat (full access)' : 'AI Gateway Chat (read-only)';
    }

    /**
     * @throws \RuntimeException when the admin disabled the chat's token.
     */
    public function tokenFor(User $user, string $scope): McpAccessToken
    {
        $token = McpAccessToken::query()
            ->where('user_id', $user->id)
            ->whereNull('oauth_client_id')
            ->where('name', self::tokenName($scope))
            ->first();

        if ($token && ! $token->is_active) {
            throw new \RuntimeException('Server tools are disabled for this chat: "'.self::tokenName($scope).'" is turned off under Settings → MCP Access.');
        }

        if (! $token) {
            $generated = McpAccessToken::generate();
            $token = McpAccessToken::query()->create([
                'user_id' => $user->id,
                'name' => self::tokenName($scope),
                'token_prefix' => 'panel chat',
                'token_hash' => $generated['hash'],
                'scope' => $scope,
                'is_active' => true,
            ]);
        }

        $token->setRelation('user', $user);
        $token->touchLastUsed(request()->ip());

        return $token;
    }

    /**
     * The tools in OpenAI function-calling shape. A read-only token only
     * gets the tools it can use, plus run_command for diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(McpAccessToken $token): array
    {
        $definitions = [];

        foreach ($this->server->tools() as $tool) {
            if (! $tool->readOnly() && ! $token->allowsMutations() && $tool->name() !== 'run_command') {
                continue;
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $this->singleTypes($tool->inputSchema()),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Run one tool call from the model.
     *
     * @param  array<string, mixed>  $toolCall  OpenAI shape: {id, function: {name, arguments}}
     * @return array{name: string, arguments: array<string, mixed>, is_error: bool, content: string, preview: string}
     */
    public function call(array $toolCall, McpAccessToken $token): array
    {
        $name = (string) ($toolCall['function']['name'] ?? '');
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
        $tool = $this->server->tools()[$name] ?? null;

        if (! $tool || ! is_array($arguments)) {
            $data = ['error' => $tool ? 'arguments must be a JSON object.' : "Unknown tool: {$name}"];
            $isError = true;
        } else {
            ['data' => $data, 'is_error' => $isError] = $this->server->runTool($tool, $arguments, $token);
        }

        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (mb_strlen($json) > self::MAX_RESULT_CHARS) {
            $json = mb_substr($json, 0, self::MAX_RESULT_CHARS).'… [cut: the result was '.mb_strlen($json).' characters; ask for less, e.g. a line range or a narrower search]';
        }

        return [
            'name' => $name,
            'arguments' => is_array($arguments) ? $arguments : [],
            'is_error' => $isError,
            'content' => $json,
            'preview' => mb_substr($isError ? (string) ($data['error'] ?? '') : $json, 0, 400),
        ];
    }

    public function systemPrompt(McpAccessToken $token): string
    {
        $access = $token->allowsMutations()
            ? 'Full access: you may change the server (edit files, run commands, restart services) to fix a problem. Before a change, say what you will change and why; prefer the smallest fix; verify it afterwards; and mention the backup path the file tools return. Ask the user first before anything destructive or hard to undo (deleting data, dropping databases, stopping sites).'
            : 'Read-only access: you can inspect everything but change nothing. When you find the fix, explain it and the exact change, and tell the user they can switch the chat\'s server tools to full access to let you apply it.';

        return implode("\n", [
            'Server tools are ON for this chat. You can call dPanel\'s MCP tools to look at the real server: websites, services, logs, files and commands. These tools act on the live server, so the earlier rule that you cannot perform actions does not apply to them.',
            'To troubleshoot: get_panel_overview, get_website and find_website_errors first, then get_service_status, read_service_logs, read_log_file, list_directory, read_file and search_files.',
            $access,
            'Report what the tools actually returned; never claim a change you did not make. Every call is recorded in the panel.',
        ]);
    }

    /**
     * Gemini rejects JSON Schema type unions such as ["integer", "string"];
     * the tools cast their arguments themselves, so "string" is enough.
     */
    private function singleTypes(array $schema, bool $isPropertyMap = false): array
    {
        foreach ($schema as $key => $value) {
            // Under "properties" the keys are argument names, not keywords.
            if (! $isPropertyMap && $key === 'type' && is_array($value)) {
                $schema[$key] = in_array('string', $value, true) ? 'string' : (string) reset($value);
            } elseif (is_array($value)) {
                $schema[$key] = $this->singleTypes($value, ! $isPropertyMap && $key === 'properties');
            }
        }

        return $schema;
    }
}
