<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use App\Services\AiGateway\GatewayMedia;
use App\Services\Mcp\GatewayChatTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, OpenAI/OpenRouter-compatible AI Gateway API. Authenticated via
 * `Authorization: Bearer sk-ag-...` (see AuthenticateAiGatewayApiKey), so
 * any external client (curl, the OpenAI SDK pointed at a custom base URL,
 * OpenRouter-compatible tools) can send requests through this panel's
 * configured providers.
 */
class AiGatewayApiController extends Controller
{
    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly GatewayChatTools $chatTools,
    ) {}

    /**
     * GET /api/v1/models — only the pseudo "auto" entry is offered; the
     * gateway always picks the provider/model itself, so there's nothing
     * else for a client to choose.
     */
    public function models(): JsonResponse
    {
        return response()->json([
            'object' => 'list',
            'data' => [[
                'id' => 'auto',
                'object' => 'model',
                'created' => 0,
                'owned_by' => 'ai-gateway',
                'context_length' => null,
                'pricing' => null,
                'description' => 'Picks a provider/model automatically at request time, checking live rate-limit/cooldown state and rotating across candidates.',
            ]],
        ]);
    }

    /**
     * POST /api/v1/chat/completions — OpenAI/OpenRouter-compatible chat
     * completion. Supports `stream: true` for SSE, same event shape as
     * OpenAI's streaming API (`chat.completion.chunk` + `[DONE]`). Routing
     * is always automatic — `model` must be "auto" or omitted; the gateway
     * checks live rate-limit/cooldown state and rotates across candidates
     * instead of letting a caller pin a specific model.
     *
     * Supports OpenAI-style tool/function calling: pass `tools` (and
     * optionally `tool_choice`) to get `tool_calls` back on the response
     * message; send them back on a follow-up request as an assistant
     * message with `tool_calls` plus a `tool` role message per call
     * result (`tool_call_id` + `content`) to continue the agent loop.
     * Each provider family gets this translated to its own tool format.
     *
     * dPanel extension: `server_tools: "read"` lets the gateway itself call
     * the panel's read-only MCP tools (logs, service status, files) to answer
     * questions about this server, when the key was created by an admin.
     * Each step is reported as it starts and finishes: in a stream as a
     * chunk with an empty delta and a `dpanel_tool_step` field, otherwise as
     * `dpanel_tool_steps` on the response.
     */
    public function chatCompletions(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:system,user,assistant,tool'],
            // A string, or OpenAI's content parts: {type: text} and {type: image_url}.
            'messages.*.content' => ['nullable'],
            'messages.*.tool_calls' => ['nullable', 'array'],
            'messages.*.tool_calls.*.id' => ['nullable', 'string'],
            'messages.*.tool_calls.*.type' => ['nullable', 'string'],
            'messages.*.tool_calls.*.function.name' => ['required_with:messages.*.tool_calls', 'string'],
            'messages.*.tool_calls.*.function.arguments' => ['nullable', 'string'],
            'messages.*.tool_call_id' => ['nullable', 'string'],
            'messages.*.name' => ['nullable', 'string'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
            'stream' => ['nullable', 'boolean'],
            'tools' => ['nullable', 'array'],
            'tools.*.type' => ['nullable', 'string'],
            'tools.*.function.name' => ['required_with:tools', 'string'],
            'tools.*.function.description' => ['nullable', 'string'],
            'tools.*.function.parameters' => ['nullable', 'array'],
            'tool_choice' => ['nullable'],
            'server_tools' => ['nullable', 'in:off,read'],
        ]);

        $toolsToken = null;
        if (($data['server_tools'] ?? 'off') === 'read') {
            $creator = $request->attributes->get('ai_gateway_api_key')?->creator;
            $error = match (true) {
                ! empty($data['tools']) => 'server_tools cannot be combined with your own tools.',
                ! GatewayChatTools::allowedFor($creator) => 'server_tools needs an API key created by an admin, with MCP enabled.',
                default => null,
            };
            if ($error) {
                return response()->json(['error' => ['message' => $error, 'type' => 'invalid_request_error', 'code' => 'server_tools_unavailable']], 400);
            }

            try {
                $toolsToken = $this->chatTools->tokenFor($creator, 'read');
            } catch (\RuntimeException $e) {
                return response()->json(['error' => ['message' => $e->getMessage(), 'type' => 'invalid_request_error', 'code' => 'server_tools_unavailable']], 400);
            }
        }

        if (! empty($data['model']) && strtolower($data['model']) !== 'auto') {
            return response()->json([
                'error' => [
                    'message' => 'This gateway only supports automatic routing — omit "model" or set it to "auto".',
                    'type' => 'invalid_request_error',
                    'code' => 'unsupported_model',
                ],
            ], 400);
        }

        $data['model'] = null;

        try {
            $data['messages'] = array_map(fn (array $message): array => $this->contentParts($message), $data['messages']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['message' => $e->getMessage(), 'type' => 'invalid_request_error', 'code' => 'invalid_content']], 400);
        }

        $options = [
            'channel' => 'api',
            'operation' => 'chat',
            'created_by' => null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['max_tokens'] ?? null,
            'tools' => $data['tools'] ?? null,
            'tool_choice' => $data['tool_choice'] ?? null,
        ];

        $completionId = 'chatcmpl-'.Str::uuid();

        if ($data['stream'] ?? false) {
            return $this->streamCompletion($completionId, $data, $options, $toolsToken);
        }

        $steps = [];
        try {
            $chat = fn (array $messages, array $options): array => $this->gateway->chatAuto($data['model'], $messages, $options);
            $result = $toolsToken
                ? $this->chatTools->run($chat, $data['messages'], $options, $toolsToken, function (array $step) use (&$steps): void {
                    if ($step['status'] !== 'running') {
                        $steps[] = $step;
                    }
                })
                : $chat($data['messages'], $options);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        $message = ['role' => 'assistant', 'content' => $result['content']];
        if (! empty($result['tool_calls'])) {
            $message['tool_calls'] = $result['tool_calls'];
        }

        return response()->json([
            'id' => $completionId,
            'object' => 'chat.completion',
            'created' => now()->timestamp,
            'model' => $result['model'],
            'provider' => $result['provider']->name,
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => $result['finish_reason'] ?? 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $result['input_tokens'],
                'completion_tokens' => $result['output_tokens'],
                'total_tokens' => $result['input_tokens'] + $result['output_tokens'],
            ],
            ...($toolsToken ? ['dpanel_tool_steps' => $steps] : []),
        ]);
    }

    /**
     * POST /api/v1/images/generations — OpenAI-compatible image generation,
     * routed automatically (like chat) to a model with the "image_generation"
     * capability. Images come back as base64 (`b64_json`) only.
     */
    public function imageGenerations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'model' => ['nullable', 'string', 'max:255'],
            'n' => ['nullable', 'integer', 'min:1', 'max:4'],
            'size' => ['nullable', 'string', 'max:20'],
            'response_format' => ['nullable', 'in:b64_json'],
        ]);

        if (! empty($data['model']) && strtolower($data['model']) !== 'auto') {
            return response()->json([
                'error' => [
                    'message' => 'This gateway only supports automatic routing — omit "model" or set it to "auto".',
                    'type' => 'invalid_request_error',
                    'code' => 'unsupported_model',
                ],
            ], 400);
        }

        try {
            $result = $this->gateway->generateImage(
                null,
                $data['prompt'],
                ['n' => $data['n'] ?? 1, 'size' => ($data['size'] ?? 'auto') === 'auto' ? null : $data['size'], 'channel' => 'api', 'created_by' => null],
            );
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'created' => now()->timestamp,
            'model' => $result['model'],
            'provider' => $result['provider']->name,
            'data' => array_map(fn (array $image): array => array_filter([
                'b64_json' => $image['data'],
                'mime_type' => $image['mime'],
                'revised_prompt' => $result['text'] !== '' ? $result['text'] : null,
            ]), $result['images']),
        ]);
    }

    /**
     * OpenAI content parts to the gateway's message shape: text parts are
     * joined, image_url parts become `images`. Only data: URLs are taken —
     * the gateway does not fetch URLs on a caller's behalf.
     *
     * @throws \InvalidArgumentException
     */
    private function contentParts(array $message): array
    {
        $content = $message['content'] ?? null;
        if (! is_array($content)) {
            $message['content'] = $content === null ? null : (string) $content;

            return $message;
        }

        $texts = [];
        $images = [];
        foreach ($content as $part) {
            $type = is_array($part) ? ($part['type'] ?? '') : '';
            if ($type === 'text') {
                $texts[] = (string) ($part['text'] ?? '');
            } elseif ($type === 'image_url') {
                $url = (string) (is_array($part['image_url'] ?? null) ? ($part['image_url']['url'] ?? '') : ($part['image_url'] ?? ''));
                if (preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#is', $url, $match) !== 1) {
                    throw new \InvalidArgumentException('image_url must be a data: URL with base64 image data (data:image/png;base64,...); the gateway does not download images.');
                }
                $bytes = base64_decode($match[2], true);
                $mime = $bytes === false ? null : GatewayMedia::detectMime($bytes);
                if ($mime === null || strlen($bytes) > GatewayMedia::MAX_BYTES) {
                    throw new \InvalidArgumentException('Images must be PNG, JPEG, WebP or GIF, up to '.(GatewayMedia::MAX_BYTES / 1024 / 1024).' MB.');
                }
                $images[] = ['mime' => $mime, 'data' => base64_encode($bytes)];
            } else {
                throw new \InvalidArgumentException('Unsupported content part type: '.($type ?: 'none').'. Use "text" or "image_url".');
            }
        }

        if (count($images) > GatewayMedia::MAX_IMAGES) {
            throw new \InvalidArgumentException('At most '.GatewayMedia::MAX_IMAGES.' images per message.');
        }

        $message['content'] = implode("\n", $texts);
        if ($images !== []) {
            $message['images'] = $images;
        }

        return $message;
    }

    private function streamCompletion(string $completionId, array $data, array $options, ?McpAccessToken $toolsToken = null): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($completionId, $data, $options, $toolsToken): void {
            $created = now()->timestamp;
            $model = $data['model'] ?? null;

            $send = function (array $payload): void {
                echo 'data: '.json_encode($payload)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $chunk = fn (array|\stdClass $delta, ?string $finishReason = null) => [
                'id' => $completionId,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $model ?? '',
                'choices' => [[
                    'index' => 0,
                    'delta' => $delta,
                    'finish_reason' => $finishReason,
                ]],
            ];

            $send($chunk(['role' => 'assistant', 'content' => '']));

            $onDelta = function (string $delta) use ($send, $chunk): void {
                $send($chunk(['content' => $delta]));
            };

            try {
                $chat = fn (array $messages, array $options): array => $this->gateway->chatStreamAuto($data['model'], $messages, $options, $onDelta);
                $result = $toolsToken
                    ? $this->chatTools->run(
                        $chat,
                        $data['messages'],
                        $options,
                        $toolsToken,
                        fn (array $step) => $send([...$chunk(new \stdClass), 'dpanel_tool_step' => $step]),
                        $onDelta,
                    )
                    : $chat($data['messages'], $options);

                if (! empty($result['tool_calls'])) {
                    $send($chunk(['tool_calls' => $result['tool_calls']]));
                }

                $send($chunk(new \stdClass, $result['finish_reason'] ?? 'stop'));
            } catch (\Throwable $e) {
                $send([
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => $e instanceof AiGatewayException && $e->isRateLimitOrQuota() ? 'rate_limit_error' : 'api_error',
                    ],
                ]);
            }

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        $isRateLimit = $e instanceof AiGatewayException && $e->isRateLimitOrQuota();

        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
                'type' => $isRateLimit ? 'rate_limit_error' : 'api_error',
                'code' => $isRateLimit ? 'rate_limit_exceeded' : 'internal_error',
            ],
        ], $isRateLimit ? 429 : 502);
    }
}
