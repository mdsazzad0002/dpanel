<?php

namespace App\Services\Dns;

use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebsiteDnsProvisionService
{
    public function __construct(
        private readonly DnsRegistryService $dnsRegistry,
    ) {
    }

    /**
     * @return array{managed:bool, created:bool, reactivated:bool, skipped:string|null, nameservers:array<int, string>}
     */
    public function syncWebsite(Website $website, bool $requestedManageDns = false): array
    {
        $domain = strtolower(trim((string) ($website->domain ?? '')));
        if ($domain === '') {
            return ['managed' => false, 'created' => false, 'reactivated' => false, 'skipped' => 'empty-domain', 'nameservers' => []];
        }

        if (! $requestedManageDns) {
            return ['managed' => false, 'created' => false, 'reactivated' => false, 'skipped' => 'not-requested', 'nameservers' => []];
        }

        $observed = $this->resolveNameservers($domain);
        $required = $this->normalizedOurNameservers();
        $matches = $this->hasAllRequiredNameservers($observed, $required);

        if (! $matches) {
            $this->markZoneInactive($domain);

            return [
                'managed' => false,
                'created' => false,
                'reactivated' => false,
                'skipped' => 'external-nameservers',
                'nameservers' => $observed,
            ];
        }

        $zone = $this->upsertZone($website, true);
        $created = (bool) ($zone['created'] ?? false);
        $reactivated = (bool) ($zone['reactivated'] ?? false);
        $this->upsertDefaultRecords((string) $zone['id'], $domain, $website);

        return [
            'managed' => true,
            'created' => $created,
            'reactivated' => $reactivated,
            'skipped' => null,
            'nameservers' => $observed,
        ];
    }

    public function reconcileAll(): array
    {
        $result = ['managed' => 0, 'created' => 0, 'reactivated' => 0, 'skipped' => 0];

        Website::query()
            ->where('manage_dns', true)
            ->orderBy('domain')
            ->chunk(100, function ($websites) use (&$result): void {
                foreach ($websites as $website) {
                    $sync = $this->syncWebsite($website, true);
                    if (! empty($sync['managed'])) {
                        $result['managed']++;
                    }
                    if (! empty($sync['created'])) {
                        $result['created']++;
                    }
                    if (! empty($sync['reactivated'])) {
                        $result['reactivated']++;
                    }
                    if (($sync['skipped'] ?? null) !== null) {
                        $result['skipped']++;
                    }
                }
            });

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function resolveNameservers(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || ! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_NS);
        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record) => strtolower(trim((string) ($record['target'] ?? ''))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $observed
     * @param array<int, string> $required
     */
    public function hasAllRequiredNameservers(array $observed, array $required): bool
    {
        $observed = array_values(array_unique(array_map(fn ($value) => strtolower(trim((string) $value)), $observed)));
        $required = array_values(array_unique(array_map(fn ($value) => strtolower(trim((string) $value)), $required)));

        if ($required === []) {
            return false;
        }

        foreach ($required as $name) {
            if (! in_array($name, $observed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedOurNameservers(): array
    {
        return collect((array) config('dns.our_nameservers', []))
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{id:string,created:bool,reactivated:bool}
     */
    private function upsertZone(Website $website, bool $active): array
    {
        $domain = strtolower(trim((string) $website->domain));
        $existing = DB::table('dns_zones')->where('domain', $domain)->first();
        $now = now();

        if ($existing) {
            DB::table('dns_zones')->where('domain', $domain)->update([
                'status' => $active ? 'active' : 'inactive',
                'assigned_user_id' => $website->assigned_user_id,
                'updated_at' => $now,
            ]);

            return [
                'id' => (string) $existing->id,
                'created' => false,
                'reactivated' => ! $active ? false : strtolower((string) ($existing->status ?? 'active')) !== 'active',
            ];
        }

        $id = (string) Str::uuid();
        DB::table('dns_zones')->insert([
            'id' => $id,
            'domain' => $domain,
            'server_id' => null,
            'status' => $active ? 'active' : 'inactive',
            'assigned_user_id' => $website->assigned_user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'created' => true, 'reactivated' => false];
    }

    private function markZoneInactive(string $domain): void
    {
        DB::table('dns_zones')
            ->where('domain', strtolower(trim($domain)))
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }

    private function upsertDefaultRecords(string $zoneId, string $domain, Website $website): void
    {
        $domain = strtolower(trim($domain));
        $ttl = $this->dnsRegistry->defaultTtl();
        $rootTarget = trim((string) config('app.url', ''));
        $rootTargetHost = $rootTarget !== '' ? parse_url($rootTarget, PHP_URL_HOST) : null;
        $rootTargetValue = is_string($rootTargetHost) && $rootTargetHost !== '' ? $rootTargetHost : $domain;

        $records = [
            ['type' => 'A', 'name' => '@', 'content' => $rootTargetValue],
            ['type' => 'CNAME', 'name' => 'www', 'content' => '@'],
        ];

        foreach ($records as $record) {
            $existing = DB::table('dns_records')
                ->where('dns_zone_id', $zoneId)
                ->where('type', $record['type'])
                ->where('name', $record['name'])
                ->first();

            $payload = [
                'dns_zone_id' => $zoneId,
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => $ttl,
                'priority' => null,
                'is_active' => true,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('dns_records')->where('id', $existing->id)->update($payload);
                continue;
            }

            DB::table('dns_records')->insert($payload + [
                'id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        }
    }
}
