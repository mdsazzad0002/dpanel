<?php

namespace App\Services\AiGateway\Contracts;

use App\Models\AiGatewayProvider;

interface ProviderAdapter
{
    /**
     * Whether this adapter can handle the given provider driver.
     */
    public function supportsDriver(string $driver): bool;

    /**
     * Normalised labels/driver constants this adapter understands.
     *
     * @return array<string, string> driver => label
     */
    public static function drivers(): array;

    /**
     * Fixed connection metadata per driver (no user-editable base URL).
     *
     * @return array<string, array{base_url: string, api_key_url: string}>
     */
    public static function driverMeta(): array;

    /**
     * Perform a chat completion against the provider.
     *
     * Messages may include an assistant `tool_calls` array (OpenAI shape:
     * `[{id, type:'function', function:{name, arguments}}]`, `arguments`
     * JSON-encoded) and/or a `tool` role message with a `tool_call_id`
     * (and, for Gemini, a `name`) carrying that call's result back — each
     * adapter translates this to its own wire format.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{max_tokens?:int, temperature?:float, system?:string, tools?:array, tool_choice?:mixed}  $options
     * @return array{content:string, input_tokens:int, output_tokens:int, model:string, raw:array, tool_calls?:?array, finish_reason?:string}
     */
    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array;

    /**
     * List the models actually available on the provider's account, by
     * calling its live models endpoint. Used to populate the model picker
     * with real data instead of a static, potentially stale catalog.
     *
     * @return array<int, array{name:string, display_name:?string}>
     *
     * @throws \App\Services\AiGateway\Exceptions\AiGatewayException
     */
    public function listModels(AiGatewayProvider $provider): array;

    /**
     * Lightweight connectivity test for the provider.
     *
     * @return array{ok:bool, message:string}
     */
    public function ping(AiGatewayProvider $provider): array;

    /**
     * Perform a streaming chat completion, invoking $onDelta with each text
     * chunk as it arrives. Returns the same aggregate shape as chat() once
     * the stream ends.
     *
     * Tool calling works the same as chat() (see its docblock); tool call
     * arguments are not streamed incrementally — they arrive complete in
     * the returned `tool_calls`, only text is streamed via $onDelta.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{max_tokens?:int, temperature?:float, system?:string, tools?:array, tool_choice?:mixed}  $options
     * @param  \Closure(string):void  $onDelta
     * @return array{content:string, input_tokens:int, output_tokens:int, model:string, raw:array, tool_calls?:?array, finish_reason?:string}
     */
    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array;
}
