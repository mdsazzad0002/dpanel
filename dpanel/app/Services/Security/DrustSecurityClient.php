<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;

class DrustSecurityClient
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->request('get', '/api/v1/security', null, 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function scanWebsite(string $root, string $siteId, string $scanType, ?string $progressKey = null): array
    {
        // A full scan with ClamAV can take close to an hour on a large site.
        return $this->request('post', '/api/v1/security/scan', [
            'root' => $root,
            'site_id' => $siteId,
            'scan_type' => $scanType,
            'progress_key' => $progressKey,
        ], 3500);
    }

    /**
     * Where a running website scan is, or null once drust has finished it.
     *
     * @return array<string, mixed>|null
     */
    public function scanProgress(string $progressKey): ?array
    {
        $data = $this->request('get', '/api/v1/security/scan/progress/'.rawurlencode($progressKey), null, 5);

        return $data === [] ? null : $data;
    }

    /**
     * Jails with their banned IPs, and the IPs dPanel keeps on fail2ban's ignore list.
     *
     * @return array<string, mixed>
     */
    public function fail2banStatus(): array
    {
        return $this->request('get', '/api/v1/fail2ban', null, 30);
    }

    /**
     * @param  'unban'|'ban'|'whitelist_add'|'whitelist_remove'|'policy'  $action
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed> the fail2ban status after the change
     */
    public function fail2banAction(string $action, string $ip, array $extra = []): array
    {
        return $this->request('post', '/api/v1/fail2ban', ['action' => $action, 'ip' => $ip] + $extra, 60);
    }

    /**
     * Recent accepted and failed SSH logins, newest first.
     *
     * @return array<string, mixed>
     */
    public function sshHistory(): array
    {
        return $this->request('get', '/api/v1/fail2ban/ssh-history', null, 30);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, int $timeout): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured (SERVERPANEL_EXECUTION_API_BASE_URL).');
        }

        $request = Http::acceptJson()->asJson()->timeout($timeout);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $url = rtrim($baseUrl, '/').$path;
        try {
            $response = $method === 'get' ? $request->get($url) : $request->post($url, $payload ?? []);
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'drust request failed.'));
        }

        return (array) ($json['data'] ?? []);
    }
}
