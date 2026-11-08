<?php

namespace App\Services\Dns\Backend;

use App\Services\Dns\DnsZoneRules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * PowerDNS with the gmysql backend: its `domains` and `records` tables live
 * in the panel's own database, so a change is a transaction there.
 */
class PowerDnsBackend implements DnsBackend
{
    public function __construct(private readonly DnsZoneRules $rules) {}

    public function name(): string
    {
        return 'powerdns';
    }

    public function db(): ConnectionInterface
    {
        return DB::connection((string) config('database.default'));
    }

    public function upsertZone(ZoneSpec $zone): int
    {
        $name = self::normalize($zone->name);
        $row = ['type' => strtoupper($zone->kind), 'master' => $zone->kind === 'slave' ? $zone->primary : null];
        $existing = $this->db()->table('domains')->where('name', $name)->value('id');
        if ($existing !== null) {
            $this->db()->table('domains')->where('id', $existing)->update($row);

            return (int) $existing;
        }

        return (int) $this->db()->table('domains')->insertGetId(['name' => $name, ...$row]);
    }

    public function renameZone(string $from, string $to): void
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === $to) {
            return;
        }
        $db = $this->db();
        $db->transaction(function () use ($db, $from, $to): void {
            $id = $db->table('domains')->where('name', $from)->value('id');
            if ($id === null) {
                throw new \RuntimeException("No zone {$from}.");
            }
            $db->table('domains')->where('id', $id)->update(['name' => $to]);
            foreach ($db->table('records')->where('domain_id', $id)->get(['id', 'name']) as $record) {
                $name = self::renamed((string) $record->name, $from, $to);
                if ($name !== $record->name) {
                    $db->table('records')->where('id', $record->id)->update(['name' => $name]);
                }
            }
        });
    }

    public function deleteZone(string $name): void
    {
        $db = $this->db();
        $db->transaction(function () use ($db, $name): void {
            $id = $db->table('domains')->where('name', self::normalize($name))->value('id');
            if ($id === null) {
                return;
            }
            $db->table('records')->where('domain_id', $id)->delete();
            $db->table('domains')->where('id', $id)->delete();
        });
    }

    public function replaceRecords(string $zone, array $records): void
    {
        $zone = self::normalize($zone);
        $db = $this->db();
        $db->transaction(function () use ($db, $zone, $records): void {
            $id = $db->table('domains')->where('name', $zone)->value('id');
            if ($id === null) {
                throw new \RuntimeException("No zone {$zone}.");
            }
            $rows = [];
            foreach ($records as $record) {
                $name = self::normalize($record->name);
                if ($name !== $zone && ! str_ends_with($name, '.'.$zone)) {
                    throw new \InvalidArgumentException("{$record->name} is not in {$zone}.");
                }
                if (strtoupper($record->type) === 'SOA') {
                    continue;
                }
                $rows[] = [
                    'domain_id' => $id, 'name' => $name, 'type' => strtoupper($record->type),
                    'content' => $record->content, 'ttl' => max(60, $record->ttl),
                    'prio' => $record->priority, 'disabled' => $record->disabled ? 1 : 0, 'auth' => 1,
                ];
            }
            $db->table('records')->where('domain_id', $id)->where('type', '!=', 'SOA')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('records')->insert($chunk);
            }
            $this->rules->bumpSerial($db, (int) $id);
        });
    }

    public function enableDnssec(string $zone, bool $on): array
    {
        throw new UnsupportedDnsFeature('DNSSEC needs the gmysql-dnssec schema first (backlog M6).');
    }

    private static function normalize(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.');
    }

    private static function renamed(string $name, string $from, string $to): string
    {
        if ($name === $from) {
            return $to;
        }

        return str_ends_with($name, '.'.$from) ? substr($name, 0, -strlen($from)).$to : $name;
    }
}
