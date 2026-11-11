<?php

namespace App\Services\Platform;

use App\Services\Dns\Backend\UnsupportedDnsFeature;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * drust's `/api/v1/dns/dnssec/{zone}` (backlog M6): signing a PowerDNS zone
 * needs `pdnsutil`, which only root can run.
 */
class DnssecClient
{
    /** @return array{enabled: bool, ds: list<string>} */
    public function status(string $zone): array
    {
        return $this->state($this->check($this->request()->get($this->url($zone)), $zone));
    }

    /** @return array{enabled: bool, ds: list<string>} */
    public function set(string $zone, bool $enabled): array
    {
        return $this->state($this->check($this->request()->put($this->url($zone), ['enabled' => $enabled]), $zone));
    }

    /** After a signed zone's records change: `auth` fields and empty non-terminals. */
    public function rectify(string $zone): void
    {
        $this->check($this->request()->post($this->url($zone).'/rectify'), $zone);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->timeout(max(30, (int) config('serverpanel.execution_api_timeout', 60)))
            ->withToken(trim((string) config('serverpanel.execution_api_token', '')));
    }

    private function url(string $zone): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        return rtrim($baseUrl, '/').'/api/v1/dns/dnssec/'.rawurlencode(rtrim(strtolower(trim($zone)), '.'));
    }

    /** @return array<string, mixed> */
    private function check(Response $response, string $zone): array
    {
        $json = $response->json();
        if ($response->status() === 501) {
            throw new UnsupportedDnsFeature('DNSSEC is not available on this server.');
        }
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException("DNSSEC for {$zone}: ".($message !== '' ? $message : $response->body()));
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{enabled: bool, ds: list<string>}
     */
    private function state(array $json): array
    {
        $ds = array_values(array_filter(array_map('strval', (array) ($json['ds'] ?? [])), fn (string $line) => $line !== ''));

        return ['enabled' => (bool) ($json['enabled'] ?? false), 'ds' => $ds];
    }
}
