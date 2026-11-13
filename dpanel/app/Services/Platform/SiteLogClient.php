<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Http;

/**
 * drust's `GET /sites/logs` (backlog S4): the end of a site's access log,
 * or of its account's PHP error log.
 */
class SiteLogClient
{
    /** @return array{exists: bool, lines: list<string>} */
    public function tail(string $kind, string $domain, string $owner, int $lines): array
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        $response = Http::acceptJson()->timeout(30)
            ->withToken(trim((string) config('dpanel.execution_api_token', '')))
            ->get(rtrim($baseUrl, '/').'/sites/logs', ['kind' => $kind, 'domain' => $domain, 'owner' => $owner, 'lines' => $lines]);

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'Log request failed: '.$response->status());
        }

        return ['exists' => (bool) ($json['exists'] ?? false), 'lines' => array_values(array_map('strval', (array) ($json['lines'] ?? [])))];
    }
}
