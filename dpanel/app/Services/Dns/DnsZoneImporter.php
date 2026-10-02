<?php

namespace App\Services\Dns;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a batch of records to a zone (from a zone file or a scan), checking
 * each the same way the record form does.
 */
class DnsZoneImporter
{
    public function __construct(
        private readonly DnsRecordContent $recordContent,
        private readonly DnsZoneRules $rules,
    ) {}

    /**
     * @param  list<array{name:string,type:string,content:string,priority:int|null,ttl:int}>  $records  FQDN names
     * @param  bool  $replace  remove every record except SOA and the apex NS set first
     * @return array{created:int, skipped:list<string>}
     */
    public function import(DnsZone $profile, string $domain, array $records, bool $replace = false): array
    {
        $zoneId = (int) $profile->powerdns_domain_id;
        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($profile, $domain, $records, $replace, $zoneId, &$created, &$skipped): void {
            if ($replace) {
                // Keep SOA and the apex NS set; they describe this server.
                $kept = DB::table('records')->where('domain_id', $zoneId)
                    ->where(fn ($query) => $query->where('type', 'SOA')->orWhere(fn ($ns) => $ns->where('type', 'NS')->where('name', $domain)))
                    ->pluck('id');
                DB::table('records')->where('domain_id', $zoneId)->whereNotIn('id', $kept)->delete();
                DnsRecord::query()->where('dns_zone_id', $profile->id)->whereNotIn('powerdns_record_id', $kept)->delete();
            }

            foreach ($records as $record) {
                $name = rtrim(strtolower(trim((string) $record['name'])), '.');
                $type = strtoupper((string) $record['type']);
                $ttl = max(60, min(86400, (int) ($record['ttl'] ?? 3600)));
                try {
                    if ($name !== $domain && ! str_ends_with($name, ".{$domain}")) {
                        throw new \InvalidArgumentException("{$name} is outside {$domain}.");
                    }
                    $this->recordContent->assertValidName($name);
                    ['content' => $content, 'priority' => $priority] = $this->recordContent->normalize($type, (string) $record['content'], isset($record['priority']) ? (int) $record['priority'] : null, $domain);
                    $duplicate = DB::table('records')->where('domain_id', $zoneId)->where('name', $name)
                        ->where('type', $type)->where('content', $content)->exists();
                    if ($duplicate) {
                        continue;
                    }
                    $this->rules->assertNoCnameConflict(DB::connection(), $zoneId, $domain, $name, $type);
                } catch (\InvalidArgumentException $exception) {
                    $skipped[] = "{$type} {$name}: {$exception->getMessage()}";

                    continue;
                }

                $recordId = (int) DB::table('records')->insertGetId([
                    'domain_id' => $zoneId,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'prio' => $priority,
                    'disabled' => 0,
                    'auth' => 1,
                ]);
                DnsRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'dns_zone_id' => $profile->id,
                    'powerdns_record_id' => $recordId,
                    'type' => $type,
                    'name' => $name,
                    'content' => $content,
                    'ttl' => $ttl,
                    'priority' => $priority,
                    'is_active' => true,
                ]);
                $created++;
            }
            $this->rules->bumpSerial(DB::connection(), $zoneId);
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
