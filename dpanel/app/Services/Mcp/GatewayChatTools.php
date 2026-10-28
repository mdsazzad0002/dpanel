<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\Mcp\Contracts\McpTool;

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

    /** Model turns per message; each turn may call several tools. */
    private const MAX_TOOL_ROUNDS = 15;

    public function __construct(
        private readonly McpServer $server,
        private readonly McpCommandPolicy $policy,
    ) {}

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
                    'description' => $this->describe($tool, $token),
                    'parameters' => $this->singleTypes($tool->inputSchema()),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * One reply with server tools: the model may call tools, each result goes
     * back to it, and this repeats until it answers in text. Every step is
     * reported to $onStep as it starts and as it finishes, so callers can
     * stream it: {id, name, arguments, status: running} then
     * {id, name, status: ok|error, preview, duration_ms}.
     *
     * @param  \Closure(array, array): array  $chat  One streamed model turn: ($messages, $options) => result.
     * @param  \Closure(array): void  $onStep
     * @param  \Closure(string): void|null  $onDelta  Gets the separator between turns' text.
     * @return array<string, mixed> The last turn's result, with all turns' text and token counts.
     */
    public function run(\Closure $chat, array $messages, array $options, McpAccessToken $token, \Closure $onStep, ?\Closure $onDelta = null): array
    {
        // After the leading system messages (the persona, if any), so it
        // overrides a "you cannot act" rule there.
        $at = 0;
        while (($messages[$at]['role'] ?? null) === 'system') {
            $at++;
        }
        array_splice($messages, $at, 0, [['role' => 'system', 'content' => $this->systemPrompt($token)]]);
        $options['tools'] = $this->definitions($token);

        $texts = [];
        $inputTokens = 0;
        $outputTokens = 0;

        for ($round = 0; ; $round++) {
            if ($round === self::MAX_TOOL_ROUNDS) {
                // Out of turns: one last answer, with tools still defined (some
                // providers require that once tool calls are in the history).
                $options['tool_choice'] = 'none';
                $messages[] = ['role' => 'user', 'content' => 'Tool limit for this message reached. Summarize what you found and changed so far, and what is left to do.'];
            }

            $result = $chat($messages, $options);
            $inputTokens += (int) ($result['input_tokens'] ?? 0);
            $outputTokens += (int) ($result['output_tokens'] ?? 0);

            $text = trim((string) ($result['content'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }

            if (empty($result['tool_calls']) || $round >= self::MAX_TOOL_ROUNDS) {
                break;
            }

            if ($text !== '' && $onDelta) {
                $onDelta("\n\n");
            }

            // Each result must name its call; not every provider sends ids.
            $calls = array_map(
                fn (array $call, int $index): array => [...$call, 'id' => (string) ($call['id'] ?? '') ?: "call_{$round}_{$index}"],
                $result['tool_calls'],
                array_keys($result['tool_calls']),
            );
            $messages[] = ['role' => 'assistant', 'content' => $result['content'] ?? '', 'tool_calls' => $calls];

            foreach ($calls as $call) {
                $id = $call['id'];
                $onStep(['id' => $id, 'name' => $call['function']['name'] ?? '', 'arguments' => json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: new \stdClass, 'status' => 'running']);

                $started = hrtime(true);
                $outcome = $this->call($call, $token);
                $onStep([
                    'id' => $id,
                    'name' => $outcome['name'],
                    'status' => $outcome['is_error'] ? 'error' : 'ok',
                    'preview' => $outcome['preview'],
                    'duration_ms' => intdiv(hrtime(true) - $started, 1000000),
                ]);

                $messages[] = ['role' => 'tool', 'tool_call_id' => $id, 'name' => $outcome['name'], 'content' => $outcome['content']];
            }
        }

        return [...$result, 'content' => implode("\n\n", $texts), 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens];
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
            : 'Read-only access: you can inspect everything but change nothing. run_command only accepts the read-only commands listed in its description; anything else is refused, so do not try it. When you find the fix, explain it and the exact change, and tell the user they can switch the chat\'s server tools to full access to let you apply it.';

        return implode("\n", [
            'Server tools are ON for this chat. You can call dPanel\'s MCP tools to look at the real server: websites, services, logs, files and commands. These tools act on the live server, so the earlier rule that you cannot perform actions does not apply to them.',
            'About this server: dPanel runs websites behind its own web server, edge-gateway (part of drust), with PHP-FPM, MariaDB/MySQL, Redis, Postfix/Dovecot and PowerDNS. There is no nginx or Apache, so do not look for their services or logs. Site logs come from get_website and find_website_errors.',
            'Work efficiently: call get_panel_overview first; it already gives memory, disk, load, every core service\'s state and memory use, and the processes using the most memory. Do not re-check with commands what it already answered. Then use the dedicated tool for the job (get_website, find_website_errors, get_service_status, read_service_logs, read_log_file, list_directory, read_file, search_files) and reach for run_command only when no tool covers it.',
            'If a tool call is refused or fails, do not retry the same thing with small variations; read the error, switch to another tool, or tell the user what is needed.',
            $access,
            'Report what the tools actually returned; never claim a change you did not make. Every call is recorded in the panel.',
        ]);
    }

    /**
     * The tool's own description, except that run_command tells a read-only
     * token exactly which commands it may run, so the model does not guess.
     */
    private function describe(McpTool $tool, McpAccessToken $token): string
    {
        if ($tool->name() !== 'run_command' || $token->allowsMutations()) {
            return $tool->description();
        }

        return 'Run a read-only diagnostic shell command on the server and return its output. With this chat\'s read-only access, only commands starting with one of these are accepted: '
            .implode(', ', $this->policy->readOnlyCommands())
            .'. Output may be piped into grep, head, tail, wc, sort, uniq, cut or column. Chaining (; && ||), redirects, $(…) and sensitive paths (.env, keys, /proc) are refused. Prefer the dedicated tools: get_panel_overview for memory, disk and services; read_service_logs and read_log_file for logs.';
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
