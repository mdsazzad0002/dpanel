<?php

namespace App\Services\Platform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * drust's firewall endpoints (spec section 8): rules of the shapes dPanel
 * writes, and the lock-out confirm. Every add or remove comes back with a
 * `confirm_token`; drust undoes the change unless it is confirmed in time.
 */
class FirewallClient
{
    /** @return array{status: array<string, mixed>, rules: list<array<string, mixed>>} */
    public function list(): array
    {
        $json = $this->check($this->request()->get($this->url('/firewall/rules')));

        return ['status' => (array) ($json['status'] ?? []), 'rules' => array_values((array) ($json['rules'] ?? []))];
    }

    /**
     * @param  array{action: string, port: array{from: int, to: ?int, protocol: string}|null, source: ?string}  $rule
     * @return array{id: string, confirm_token: ?string, confirm_within_seconds: ?int, message: string}
     */
    public function add(array $rule): array
    {
        return $this->changed($this->check($this->request()->post($this->url('/firewall/rules'), $rule)));
    }

    /** @return array{id: string, confirm_token: ?string, confirm_within_seconds: ?int, message: string} */
    public function remove(string $id): array
    {
        return $this->changed($this->check($this->request()->delete($this->url('/firewall/rules/'.rawurlencode($id)))));
    }

    /** `false` when drust no longer waits for it: the change was already undone. */
    public function confirm(string $token): bool
    {
        $response = $this->request()->post($this->url('/firewall/confirm'), ['token' => $token]);
        if ($response->status() === 404) {
            return false;
        }
        $this->check($response);

        return true;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{id: string, confirm_token: ?string, confirm_within_seconds: ?int, message: string}
     */
    private function changed(array $json): array
    {
        return [
            'id' => (string) ($json['id'] ?? ''),
            'confirm_token' => isset($json['confirm_token']) ? (string) $json['confirm_token'] : null,
            'confirm_within_seconds' => isset($json['confirm_within_seconds']) ? (int) $json['confirm_within_seconds'] : null,
            'message' => (string) ($json['message'] ?? 'Done.'),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->timeout(30)
            ->withToken(trim((string) config('dpanel.execution_api_token', '')));
    }

    private function url(string $path): string
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/').$path;
    }

    /** @return array<string, mixed> */
    private function check(Response $response): array
    {
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'Firewall request failed: '.$response->status());
        }

        return $json;
    }
}
