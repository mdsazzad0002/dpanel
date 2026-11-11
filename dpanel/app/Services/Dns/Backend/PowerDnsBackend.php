<?php

namespace App\Services\Dns\Backend;

use App\Services\Dns\DnsSecondaryServers;
use App\Services\Dns\DnsZoneRules;
use App\Services\Platform\DnssecClient;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PowerDNS with the gmysql backend: its `domains` and `records` tables live
 * in the panel's own database, so a change is a transaction there. Signing
 * (M6) needs `pdnsutil`, so it goes through drust; a signed zone is
 * rectified after its records change. A zone with secondaries (M7) is a
 * MASTER zone, which PowerDNS notifies about, with its ACLs in
 * `domainmetadata`; a SLAVE zone is filled by PowerDNS from its primary.
 */
class PowerDnsBackend implements DnsBackend
{
    /** What M7 keeps in `domainmetadata`, one row per secondary. */
    private const TRANSFER_KINDS = ['ALLOW-AXFR-FROM', 'ALSO-NOTIFY'];

    public function __construct(
        private readonly DnsZoneRules $rules,
        private readonly DnssecClient $dnssec,
        private readonly DnsSecondaryServers $secondaries,
    ) {}

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
        } else {
            $existing = $this->db()->table('domains')->insertGetId(['name' => $name, ...$row]);
        }
        if ($zone->kind !== 'slave') {
            $this->setTransfers($name, $this->secondaries->addresses());
        }

        return (int) $existing;
    }

    public function setTransfers(string $zone, array $secondaries): void
    {
        $name = self::normalize($zone);
        $db = $this->db();
        $row = $db->table('domains')->where('name', $name)->first(['id', 'type']);
        if ($row === null) {
            throw new \RuntimeException("No zone {$name}.");
        }
        if (strtoupper((string) $row->type) === 'SLAVE') {
            return;
        }
        $db->transaction(function () use ($db, $row, $secondaries): void {
            // NATIVE zones are never notified about, so with secondaries the zone is MASTER.
            $db->table('domains')->where('id', $row->id)->update(['type' => $secondaries === [] ? 'NATIVE' : 'MASTER']);
            if (! $this->hasTable('domainmetadata')) {
                return;
            }
            $db->table('domainmetadata')->where('domain_id', $row->id)->whereIn('kind', self::TRANSFER_KINDS)->delete();
            $rows = [];
            foreach (array_values(array_unique($secondaries)) as $address) {
                foreach (self::TRANSFER_KINDS as $kind) {
                    $rows[] = ['domain_id' => $row->id, 'kind' => $kind, 'content' => $address];
                }
            }
            if ($rows !== []) {
                $db->table('domainmetadata')->insert($rows);
            }
        });
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
        $this->rectifyIfSigned($to);
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
            foreach (['cryptokeys', 'domainmetadata'] as $table) {
                if ($this->hasTable($table)) {
                    $db->table($table)->where('domain_id', $id)->delete();
                }
            }
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
        $this->rectifyIfSigned($zone);
    }

    public function addRecord(int $zoneId, RecordSpec $record): int
    {
        return (int) $this->db()->table('records')->insertGetId([
            'domain_id' => $zoneId,
            'name' => self::normalize($record->name),
            'type' => strtoupper($record->type),
            'content' => $record->content,
            'ttl' => $record->ttl,
            'prio' => $record->priority,
            'disabled' => $record->disabled ? 1 : 0,
            'auth' => 1,
        ]);
    }

    public function updateRecord(int $recordId, array $changes): void
    {
        $columns = ['zone_id' => 'domain_id', 'name' => 'name', 'type' => 'type', 'content' => 'content', 'ttl' => 'ttl', 'priority' => 'prio', 'disabled' => 'disabled'];
        $row = [];
        foreach ($changes as $field => $value) {
            if (! isset($columns[$field])) {
                throw new \InvalidArgumentException("Unknown record field {$field}.");
            }
            $row[$columns[$field]] = match ($field) {
                'name' => self::normalize((string) $value),
                'type' => strtoupper((string) $value),
                'disabled' => $value ? 1 : 0,
                default => $value,
            };
        }
        if ($row !== []) {
            $this->db()->table('records')->where('id', $recordId)->update($row + ['auth' => 1]);
        }
    }

    public function deleteRecords(array $recordIds): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $recordIds))), 500) as $chunk) {
            $this->db()->table('records')->whereIn('id', $chunk)->delete();
        }
    }

    public function touchZone(int $zoneId): void
    {
        $this->rules->bumpSerial($this->db(), $zoneId);
        $name = $this->db()->table('domains')->where('id', $zoneId)->value('name');
        if ($name !== null) {
            $this->rectifyIfSigned((string) $name);
        }
    }

    public function enableDnssec(string $zone, bool $on): array
    {
        return $this->dnssec->set(self::normalize($zone), $on);
    }

    public function dnssecStatus(string $zone): array
    {
        return $this->dnssec->status(self::normalize($zone));
    }

    public function isSigned(string $zone): bool
    {
        if (! $this->hasTable('cryptokeys')) {
            return false;
        }
        $id = $this->db()->table('domains')->where('name', self::normalize($zone))->value('id');

        return $id !== null && $this->db()->table('cryptokeys')->where('domain_id', $id)->exists();
    }

    /** @var array<string, bool> */
    private array $tables = [];

    /** A database made by DnsController's fallback has no DNSSEC tables, so nothing there is signed. */
    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->db()->getSchemaBuilder()->hasTable($table);
    }

    /**
     * A signed zone answers wrongly for names whose `auth` or empty
     * non-terminals are stale. The records are already saved, so a failure
     * here is logged, not thrown; the next change rectifies again.
     */
    private function rectifyIfSigned(string $zone): void
    {
        if (! $this->isSigned($zone)) {
            return;
        }
        try {
            $this->dnssec->rectify(self::normalize($zone));
        } catch (\Throwable $error) {
            Log::warning('Could not rectify a signed DNS zone.', ['zone' => $zone, 'error' => $error->getMessage()]);
        }
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
