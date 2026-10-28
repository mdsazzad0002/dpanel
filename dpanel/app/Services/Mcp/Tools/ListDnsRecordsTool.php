<?php

namespace App\Services\Mcp\Tools;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;

class ListDnsRecordsTool implements McpTool
{
    public function name(): string
    {
        return 'list_dns_records';
    }

    public function title(): string
    {
        return 'List DNS records';
    }

    public function description(): string
    {
        return 'DNS zone hosted by the panel (PowerDNS) for a domain: its records, plus checks for what a site and its mail need (apex and www address, MX, SPF, DMARC, DKIM, duplicate CNAMEs). Without a domain, lists the zones.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'The zone, e.g. example.com. Omit to list zones.'],
                'type' => ['type' => 'string', 'description' => 'Only records of this type, e.g. A, MX, TXT.'],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $domain = rtrim(mb_strtolower(trim((string) ($arguments['domain'] ?? ''))), '.');

        if ($domain === '') {
            return [
                'zones' => DnsZone::query()->visibleTo($token->user)->withCount('records')->orderBy('domain')->limit(200)->get()
                    ->map(fn (DnsZone $zone) => [
                        'domain' => $zone->domain,
                        'status' => $zone->status,
                        'records' => $zone->records_count,
                        'dnssec' => (bool) $zone->dnssec_enabled,
                    ])->values()->all(),
            ];
        }

        $zone = DnsZone::query()->visibleTo($token->user)->where('domain', preg_replace('/^www\./', '', $domain))->first();
        if (! $zone) {
            throw new McpToolException("No DNS zone for {$domain} on this panel; its DNS is hosted elsewhere. Call list_dns_records without a domain to see the zones here.");
        }

        $type = strtoupper(trim((string) ($arguments['type'] ?? '')));
        $records = $zone->records()->orderBy('type')->orderBy('name')->get();

        return [
            'zone' => $zone->domain,
            'status' => $zone->status,
            'dnssec' => (bool) $zone->dnssec_enabled,
            'records' => $records
                ->when($type !== '', fn ($all) => $all->where('type', $type))
                ->map(fn (DnsRecord $record) => [
                    'type' => $record->type,
                    'name' => $record->name,
                    'content' => mb_substr((string) $record->content, 0, 500),
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                    'active' => (bool) $record->is_active,
                    'proxied' => (bool) $record->proxied,
                ])->values()->all(),
            'checks' => $this->checks($zone->domain, $records->where('is_active', true)),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DnsRecord>  $records
     * @return array<int, string>
     */
    private function checks(string $zone, $records): array
    {
        $named = fn (string $name) => $records->filter(fn (DnsRecord $r) => in_array(rtrim(mb_strtolower((string) $r->name), '.'), $name === '@' ? ['@', '', $zone] : [$name, "{$name}.{$zone}"], true));
        $apex = $named('@');
        $www = $named('www');
        $txt = fn ($set) => $set->where('type', 'TXT')->map(fn (DnsRecord $r) => trim((string) $r->content, '"'));

        $checks = [];
        $checks[] = $apex->whereIn('type', ['A', 'AAAA', 'ALIAS'])->isNotEmpty() ? 'ok: apex has an address record' : "missing: no A/AAAA record for {$zone}";
        $checks[] = $www->isNotEmpty() ? 'ok: www exists' : "missing: no record for www.{$zone}";
        $checks[] = $apex->where('type', 'MX')->isNotEmpty() ? 'ok: MX present' : 'missing: no MX record (mail to this domain will not arrive)';

        $spf = $txt($apex)->filter(fn ($v) => str_starts_with(strtolower($v), 'v=spf1'));
        $checks[] = match (true) {
            $spf->count() > 1 => 'error: more than one SPF record (receivers treat that as no SPF)',
            $spf->isEmpty() => 'missing: no SPF record',
            default => 'ok: SPF '.$spf->first(),
        };
        $checks[] = $txt($named('_dmarc'))->contains(fn ($v) => str_starts_with(strtolower($v), 'v=dmarc1')) ? 'ok: DMARC present' : 'missing: no DMARC record at _dmarc';
        $checks[] = $records->contains(fn (DnsRecord $r) => str_contains(mb_strtolower((string) $r->name), '_domainkey')) ? 'ok: DKIM key present' : 'missing: no DKIM (_domainkey) record';

        foreach ($records->groupBy(fn (DnsRecord $r) => mb_strtolower((string) $r->name)) as $name => $set) {
            if ($set->where('type', 'CNAME')->isNotEmpty() && $set->count() > 1) {
                $checks[] = "error: {$name} has a CNAME next to other records, which DNS does not allow";
            }
        }

        return $checks;
    }
}
