<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Exceptions\AiGatewayException;
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
    ) {
    }

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
     */
    public function chatCompletions(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string'],
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

        $data['model'] = null;

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
            return $this->streamCompletion($completionId, $data, $options);
        }

        try {
            $result = $this->gateway->chatAuto($data['model'], $data['messages'], $options);
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
        ]);
    }

    private function streamCompletion(string $completionId, array $data, array $options): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($completionId, $data, $options): void {
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
                $result = $this->gateway->chatStreamAuto($data['model'], $data['messages'], $options, $onDelta);

                if (! empty($result['tool_calls'])) {
                    $send($chunk(['tool_calls' => $result['tool_calls']]));
                }

                $send($chunk(new \stdClass(), $result['finish_reason'] ?? 'stop'));
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
