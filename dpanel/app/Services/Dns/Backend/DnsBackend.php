<?php

namespace App\Services\Dns\Backend;

/**
 * Where a zone is served from (spec section 6.10, backlog I3). PowerDNS now;
 * the Windows DNS Server, Cloudflare and a local hosts file come later
 * (J6, K5, local profiles). The panel's DnsZone and DnsRecord rows are the
 * record of what was asked for; a backend makes the server answer that.
 */
interface DnsBackend
{
    /** The backend's own name for logs and the zone list, such as `powerdns`. */
    public function name(): string;

    /**
     * Creates the zone, or changes its kind when it exists.
     *
     * @return int the backend's id for the zone
     */
    public function upsertZone(ZoneSpec $zone): int;

    /** A zone that was renamed keeps its id and records; their names follow. */
    public function renameZone(string $from, string $to): void;

    /** The zone and every record in it. Gone already is not an error. */
    public function deleteZone(string $name): void;

    /**
     * Every record but the SOA becomes exactly `$records`; the SOA serial moves on.
     *
     * @param  list<RecordSpec>  $records
     */
    public function replaceRecords(string $zone, array $records): void;

    /**
     * One record, for a form that edits records one at a time. The serial is
     * left alone: call {@see touchZone()} once after a batch.
     *
     * @return int the backend's id for the record
     */
    public function addRecord(int $zoneId, RecordSpec $record): int;

    /**
     * Changes only the given fields of one record.
     *
     * @param  array{zone_id?: int, name?: string, type?: string, content?: string, ttl?: int, priority?: int|null, disabled?: bool}  $changes
     */
    public function updateRecord(int $recordId, array $changes): void;

    /**
     * Records already gone are not an error.
     *
     * @param  list<int>  $recordIds
     */
    public function deleteRecords(array $recordIds): void;

    /** Moves the SOA serial on, so secondaries and caches see the change. */
    public function touchZone(int $zoneId): void;

    /**
     * Signs the zone, or removes its keys; asking for what it already is
     * changes nothing. `ds` is what the registrar needs (M6).
     *
     * @return array{enabled: bool, ds: list<string>}
     *
     * @throws UnsupportedDnsFeature where this server cannot sign zones
     */
    public function enableDnssec(string $zone, bool $on): array;

    /**
     * @return array{enabled: bool, ds: list<string>}
     *
     * @throws UnsupportedDnsFeature where this server cannot sign zones
     */
    public function dnssecStatus(string $zone): array;
}
