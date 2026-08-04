<?php

namespace App\Services\Dns;

use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DnsRegistryService
{
    private const NAMESERVER_TABLE = 'serverpanel_dns_nameservers';

    public function engine(): string
    {
        return (string) config('dns.engine', 'powerdns');
    }

    public function authoritativeMode(): string
    {
        return (string) config('dns.authoritative_mode', 'database');
    }

    public function defaultTtl(): int
    {
        return max(60, (int) config('dns.default_ttl', 3600));
    }

    public function allowDynamicUpdates(): bool
    {
        return (bool) config('dns.allow_dynamic_updates', true);
    }

    public function websites(): array
    {
        return $this->readDomainsFromTable('websites', Website::query()->pluck('domain'));
    }

    public function mailboxes(): array
    {
        return $this->readDomainsFromTable('mailboxes', Mailbox::query()->pluck('domain'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nameservers(): array
    {
        if (! $this->hasNameserverTable()) {
            return [];
        }

        return DB::table(self::NAMESERVER_TABLE)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => [
                'domain' => (string) $row->domain,
                'hostname' => (string) $row->hostname,
                'ipv4' => $row->ipv4 ? (string) $row->ipv4 : null,
                'ipv6' => $row->ipv6 ? (string) $row->ipv6 : null,
                'ttl' => (int) $row->ttl,
                'status' => (string) $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    public function providerLabel(): string
    {
        return match ($this->engine()) {
            'powerdns' => 'PowerDNS-style registry',
            'bind9' => 'BIND9 authoritative registry',
            'coredns' => 'CoreDNS authoritative registry',
            default => 'Custom DNS registry',
        };
    }

    private function hasNameserverTable(): bool
    {
        try {
            return Schema::hasTable(self::NAMESERVER_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $domains
     * @return array<int, string>
     */
    private function readDomainsFromTable(string $table, Collection $domains): array
    {
        try {
            if (! Schema::hasTable($table)) {
                return [];
            }

            return $domains
                ->filter(fn ($domain) => is_string($domain) && trim((string) $domain) !== '')
                ->map(fn ($domain) => strtolower(trim((string) $domain)))
                ->unique()
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
