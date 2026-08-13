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
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{max_tokens?:int, temperature?:float, system?:string}  $options
     * @return array{content:string, input_tokens:int, output_tokens:int, model:string, raw:array}
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
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{max_tokens?:int, temperature?:float, system?:string}  $options
     * @param  \Closure(string):void  $onDelta
     * @return array{content:string, input_tokens:int, output_tokens:int, model:string, raw:array}
     */
    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array;
}
