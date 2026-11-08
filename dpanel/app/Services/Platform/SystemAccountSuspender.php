<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Http;

/**
 * Blocks and reopens a website's system account through drust
 * (POST /accounts/{name}/suspend), which stops its SSH and SFTP logins.
 */
class SystemAccountSuspender
{
    /** The panel's own accounts; drust refuses them too. */
    private const NEVER = ['root', 'www-data', 'nobody'];

    public function suspend(string $account): void
    {
        $this->send($account, 'suspend');
    }

    public function unsuspend(string $account): void
    {
        $this->send($account, 'unsuspend');
    }

    public static function suspendable(string $account): bool
    {
        return $account !== '' && ! in_array($account, self::NEVER, true);
    }

    private function send(string $account, string $action): void
    {
        if (! self::suspendable($account)) {
            return;
        }
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken(trim((string) config('serverpanel.execution_api_token', '')))
            ->post(rtrim($baseUrl, '/').'/accounts/'.rawurlencode($account).'/'.$action);

        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException("Account {$account} {$action} failed: ".((string) ($json['message'] ?? $response->body())));
        }
    }
}
