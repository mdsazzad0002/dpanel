<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Http;

/**
 * drust's `GET /system/addresses` (backlog U5): the IP addresses on the
 * server's interfaces, without loopback and link-local ones. The panel
 * never runs `ip addr` for the pool itself.
 */
class ServerAddressClient
{
    /**
     * `addresses` is null when the list could not be read; `error` says why.
     *
     * @return array{addresses: ?list<array{interface: string, address: string, prefix: int, family: string, public: bool}>, error: ?string}
     */
    public function list(): array
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return ['addresses' => null, 'error' => 'drust execution API is not configured.'];
        }

        try {
            $response = Http::acceptJson()->timeout(10)
                ->withToken(trim((string) config('dpanel.execution_api_token', '')))
                ->get(rtrim($baseUrl, '/').'/system/addresses');
        } catch (\Throwable) {
            return ['addresses' => null, 'error' => 'drust is not answering.'];
        }

        if ($response->status() === 501) {
            return ['addresses' => null, 'error' => 'Listing the server\'s addresses is not supported on this system yet.'];
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json['addresses'] ?? null)) {
            return ['addresses' => null, 'error' => 'drust answered '.$response->status().'.'];
        }

        $addresses = [];
        foreach ($json['addresses'] as $row) {
            $address = is_array($row) ? (string) ($row['address'] ?? '') : '';
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $addresses[] = [
                'interface' => (string) ($row['interface'] ?? ''),
                'address' => $address,
                'prefix' => (int) ($row['prefix'] ?? 0),
                'family' => str_contains($address, ':') ? 'ipv6' : 'ipv4',
                'public' => (bool) ($row['public'] ?? false),
            ];
        }

        return ['addresses' => $addresses, 'error' => null];
    }
}
