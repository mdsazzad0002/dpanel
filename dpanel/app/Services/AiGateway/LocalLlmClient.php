<?php

namespace App\Services\AiGateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to drust-llm, the local GGUF model runner (see docs/local-llm.md).
 * Every method throws a RuntimeException with drust-llm's own error
 * message, so controllers can show it as is.
 */
class LocalLlmClient
{
    public function configured(): bool
    {
        return $this->key() !== '';
    }

    public function baseUrl(): string
    {
        return (string) config('aigateway.local_llm.url');
    }

    public function key(): string
    {
        return (string) config('aigateway.local_llm.key');
    }

    /** Settings, memory and loaded models; null when the service is down. */
    public function status(): ?array
    {
        try {
            return $this->call('get', '/admin/status');
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @return array{models: array, pulls: array} */
    public function models(): array
    {
        $data = $this->call('get', '/admin/models');

        return [
            'models' => $data['models'] ?? [],
            'pulls' => (object) ($data['pulls'] ?? []),
        ];
    }

    public function pull(string $url, ?string $id = null): array
    {
        return $this->call('post', '/admin/models/pull', array_filter(['url' => $url, 'id' => $id]));
    }

    public function load(string $id): array
    {
        return $this->call('post', '/admin/models/'.rawurlencode($id).'/load', [], (int) config('aigateway.local_llm.timeout', 120));
    }

    public function unload(string $id): array
    {
        return $this->call('post', '/admin/models/'.rawurlencode($id).'/unload');
    }

    public function delete(string $id): array
    {
        return $this->call('delete', '/admin/models/'.rawurlencode($id));
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withToken($this->key())->acceptJson()->connectTimeout(3)->timeout($timeout);
    }

    private function call(string $method, string $path, array $body = [], int $timeout = 15): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The local LLM runner is not installed (AIGATEWAY_LOCAL_LLM_KEY is empty).');
        }

        try {
            $request = $this->request($timeout);
            $response = $method === 'get'
                ? $request->get($this->baseUrl().$path)
                : $request->{$method}($this->baseUrl().$path, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('drust-llm is not running at '.$this->baseUrl().'.');
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?: 'drust-llm returned HTTP '.$response->status().'.');
        }

        return $response->json() ?? [];
    }
}
