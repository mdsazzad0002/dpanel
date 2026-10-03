<?php

namespace App\Services\Mail;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsZoneRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the Mail DNS Guide records straight into a zone hosted on this
 * server's DNS, replacing the records they take the place of: the apex MX
 * set, the apex SPF TXT (other apex TXT such as site verifications is kept),
 * every TXT at the DKIM and DMARC names, and the mail host's A record. A
 * CNAME at any of those names is removed too, since it cannot coexist.
 */
class MailDnsZoneWriter
{
    private const TTL = 3600;

    public function __construct(
        private readonly DnsRecordContent $recordContent,
        private readonly DnsZoneRules $rules,
    ) {}

    /**
     * The local zone that holds $domain (the domain itself or a parent of it)
     * when the user may edit it, otherwise null.
     */
    public function zoneFor(User $user, string $domain): ?DnsZone
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '') {
            return null;
        }
        $labels = explode('.', $domain);
        $candidates = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        $zoneIds = DB::table('domains')->whereIn('name', $candidates)->pluck('id', 'name');
        // The most specific zone wins, as in DNS.
        foreach ($candidates as $candidate) {
            if (! isset($zoneIds[$candidate])) {
                continue;
            }

            return DnsZone::query()->visibleTo($user)->where('powerdns_domain_id', (int) $zoneIds[$candidate])->first();
        }

        return null;
    }

    /**
     * @param  array<int, array{type: string, name: string, value: string, priority: ?int}>  $records  from MailDnsRecords::records()
     * @return array{added: int, replaced: int, unchanged: int, skipped: list<string>}
     */
    public function apply(DnsZone $zone, string $domain, array $records): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $zoneId = (int) $zone->powerdns_domain_id;
        $zoneDomain = strtolower((string) DB::table('domains')->where('id', $zoneId)->value('name'));
        $result = ['added' => 0, 'replaced' => 0, 'unchanged' => 0, 'skipped' => []];

        DB::transaction(function () use ($zone, $domain, $zoneDomain, $zoneId, $records, &$result): void {
            foreach ($records as $record) {
                $type = strtoupper((string) $record['type']);
                $name = $record['name'] === '@' ? $domain : strtolower($record['name']).'.'.$domain;
                if ((string) $record['value'] === '') {
                    $result['skipped'][] = "{$type} {$name}: not configured yet.";

                    continue;
                }

                try {
                    $this->recordContent->assertValidName($name);
                    ['content' => $content, 'priority' => $priority] = $this->recordContent->normalize($type, (string) $record['value'], $record['priority'] ?? null, $zoneDomain);
                } catch (\InvalidArgumentException $exception) {
                    $result['skipped'][] = "{$type} {$name}: {$exception->getMessage()}";

                    continue;
                }

                $existing = $this->replaceable($zoneId, $name, $type, $name === $domain);
                $same = $existing->count() === 1
                    && (string) $existing->first()->type === $type
                    && (string) $existing->first()->content === $content
                    && (int) $existing->first()->prio === (int) $priority;
                if ($same) {
                    $result['unchanged']++;

                    continue;
                }

                if ($existing->isNotEmpty()) {
                    $ids = $existing->pluck('id')->all();
                    DB::table('records')->whereIn('id', $ids)->delete();
                    DnsRecord::query()->where('dns_zone_id', $zone->id)->whereIn('powerdns_record_id', $ids)->delete();
                    $result['replaced']++;
                } else {
                    $result['added']++;
                }

                $recordId = (int) DB::table('records')->insertGetId([
                    'domain_id' => $zoneId,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => self::TTL,
                    'prio' => $priority,
                    'disabled' => 0,
                    'auth' => 1,
                ]);
                DnsRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'dns_zone_id' => $zone->id,
                    'powerdns_record_id' => $recordId,
                    'type' => $type,
                    'name' => $name,
                    'content' => $content,
                    'ttl' => self::TTL,
                    'priority' => $priority,
                    'is_active' => true,
                ]);
            }

            $this->rules->bumpSerial(DB::connection(), $zoneId);
        });

        return $result;
    }

    /** Records at $name that the new $type record takes the place of. */
    private function replaceable(int $zoneId, string $name, string $type, bool $apex)
    {
        $rows = DB::table('records')->where('domain_id', $zoneId)->where('name', $name)
            ->whereIn('type', [$type, 'CNAME'])
            ->get(['id', 'type', 'content', 'prio']);

        // Only the SPF string is ours among apex TXT records.
        $isSpf = fn ($row): bool => str_starts_with(strtolower(ltrim($this->recordContent->unquoteTxt((string) $row->content))), 'v=spf1');
        if ($type === 'TXT' && $apex) {
            $rows = $rows->filter(fn ($row) => $row->type === 'CNAME' || $isSpf($row))->values();
        }

        return $rows;
    }
}
