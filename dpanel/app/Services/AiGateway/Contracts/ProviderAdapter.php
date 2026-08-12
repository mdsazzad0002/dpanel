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
     * Perform a chat completion against the provider.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{max_tokens?:int, temperature?:float, system?:string}  $options
     * @return array{content:string, input_tokens:int, output_tokens:int, model:string, raw:array}
     */
    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array;

    /**
     * Lightweight connectivity test for the provider.
     *
     * @return array{ok:bool, message:string}
     */
    public function ping(AiGatewayProvider $provider): array;
}
