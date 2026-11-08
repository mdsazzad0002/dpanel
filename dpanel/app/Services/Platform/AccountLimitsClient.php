<?php

namespace App\Services\Platform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * drust's `GET /accounts/{name}/usage` and `PUT /accounts/{name}/limits`
 * (spec section 8): a system account's disk use and its hard limit.
 */
class AccountLimitsClient
{
    /** @return array{disk_mb: int, level: string} */
    public function usage(string $account): array
    {
        $json = $this->check($this->request()->get($this->url($account, 'usage')), $account);

        return ['disk_mb' => (int) ($json['disk_mb'] ?? 0), 'level' => (string) ($json['disk_limit_level'] ?? 'none')];
    }

    /** `0` takes the limit away. */
    public function setDiskLimit(string $account, int $diskMb): void
    {
        $this->check($this->request()->put($this->url($account, 'limits'), ['disk_mb' => max(0, $diskMb)]), $account);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken(trim((string) config('serverpanel.execution_api_token', '')));
    }

    private function url(string $account, string $path): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/').'/accounts/'.rawurlencode($account).'/'.$path;
    }

    /** @return array<string, mixed> */
    private function check(\Illuminate\Http\Client\Response $response, string $account): array
    {
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException("Account {$account}: ".((string) (is_array($json) ? ($json['message'] ?? '') : '') ?: $response->body()));
        }

        return $json;
    }
}
