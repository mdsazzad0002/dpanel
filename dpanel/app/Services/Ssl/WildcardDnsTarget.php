<?php

namespace App\Services\Ssl;

use App\Services\Dns\DnsRegistryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Backlog M4: where the DNS-01 TXT record of a wildcard certificate goes.
 * The zone on this server's PowerDNS wins; otherwise the zone on Cloudflare,
 * when the panel has a Cloudflare API token. drust writes the record, waits
 * for the zone's nameservers to serve it and removes it again.
 */
class WildcardDnsTarget
{
    public function __construct(private readonly DnsRegistryService $registry) {}

    /**
     * What drust's `ssl/ensure` takes as `wildcard`.
     *
     * @return array{provider: string, zone: string, api_token?: string}
     */
    public function for(string $domain): array
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        $candidates = self::zoneCandidates($domain);
        if ($candidates === []) {
            throw new \RuntimeException("\"{$domain}\" cannot have a wildcard certificate.");
        }

        $zone = $this->powerDnsZone($candidates);
        if ($zone !== null) {
            return ['provider' => 'powerdns', 'zone' => $zone];
        }

        $token = trim((string) config('services.cloudflare.api_token', ''));
        if ($token !== '') {
            $zone = $this->cloudflareZone($token, $candidates);
            if ($zone !== null) {
                return ['provider' => 'cloudflare', 'zone' => $zone, 'api_token' => $token];
            }
        }

        throw new \RuntimeException(
            "A wildcard certificate needs the DNS of {$domain} on this server or on Cloudflare, so the panel can add the TXT record the CA checks. "
            .($token === '' ? 'No zone for it is on this server, and no Cloudflare API token is set (CLOUDFLARE_API_TOKEN).' : 'Neither this server nor the Cloudflare account has a zone for it.')
        );
    }

    /**
     * The domain and each parent with at least two labels, longest first.
     *
     * @return array<int, string>
     */
    public static function zoneCandidates(string $domain): array
    {
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/', $domain)) {
            return [];
        }
        $labels = explode('.', $domain);
        $candidates = [];
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates;
    }

    /** @param array<int, string> $candidates */
    private function powerDnsZone(array $candidates): ?string
    {
        if ($this->registry->engine() !== 'powerdns') {
            return null;
        }
        try {
            $names = DB::table('domains')->whereIn('name', $candidates)->pluck('name')->map(fn ($name) => strtolower((string) $name))->all();
        } catch (\Throwable) {
            // No PowerDNS tables in this database.
            return null;
        }
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<int, string> $candidates */
    private function cloudflareZone(string $token, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(20)
                    ->get('https://api.cloudflare.com/client/v4/zones', ['name' => $candidate, 'per_page' => 1]);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Cannot reach Cloudflare: '.$e->getMessage());
            }
            if ($response->status() === 401 || $response->status() === 403) {
                throw new \RuntimeException('Cloudflare refused the API token (CLOUDFLARE_API_TOKEN).');
            }
            if ((bool) $response->json('success') && is_string($response->json('result.0.name'))) {
                return strtolower((string) $response->json('result.0.name'));
            }
        }

        return null;
    }
}
