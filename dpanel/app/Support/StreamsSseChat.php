<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared SSE "chat engine" for the panel's own session-authenticated chat
 * surfaces (AI Gateway playground, command-palette "Ask AI") — same
 * event: delta/done/error protocol both frontends already parse identically.
 *
 * Deliberately NOT used by Api\AiGatewayApiController: that endpoint speaks
 * the OpenAI-compatible wire format (bare `data: {...}` / `data: [DONE]`,
 * no `event:` lines) for external API-key callers, a different contract that
 * must stay untouched by this refactor.
 */
trait StreamsSseChat
{
    /**
     * Wrap $handler in a properly-headered SSE response. $handler receives a
     * $send(event, payload) closure that writes one `event: X\ndata: Y\n\n`
     * frame and flushes immediately.
     */
    protected function sseResponse(\Closure $handler): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($handler): void {
            $send = function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $handler($send);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Streaming endpoints can't rely on Laravel's default validation-failure
     * handling: `Accept: text/event-stream` isn't recognised as "expects
     * JSON", so a failed $request->validate() would otherwise silently
     * redirect back to whatever page the session last loaded. Emit a proper
     * SSE `error` event instead.
     */
    protected function sseValidationError(ValidationException $e): StreamedResponse
    {
        return $this->sseResponse(function (\Closure $send) use ($e): void {
            $send('error', ['message' => $e->validator->errors()->first() ?: 'Invalid request.']);
        });
    }
}
