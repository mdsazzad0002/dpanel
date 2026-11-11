<?php

namespace App\Services\Dns;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\RecordSpec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes one record of a zone, for the DNS page and the
 * public API (U1). The caller has already checked that the user may edit the
 * zone. Each change goes to the DNS backend, bumps the zone's serial and is
 * mirrored in `dns_records`.
 */
class DnsRecordService
{
    public function __construct(
        private readonly DnsBackend $backend,
        private readonly DnsRecordContent $content,
        private readonly DnsZoneRules $rules,
    ) {}

    /**
     * @param  array{type: string, name: string, content: string, ttl: int|string, priority?: int|string|null, status: string}  $input
     * @return array{id: int, name: string, type: string, content: string, ttl: int, priority: ?int, status: string}
     */
    public function add(int $zoneId, string $zoneDomain, array $input): array
    {
        $this->refuseSecondary($zoneId);
        $type = strtoupper((string) $input['type']);
        ['name' => $name, 'content' => $content, 'priority' => $priority] = $this->prepare($zoneId, $zoneDomain, $input);
        $disabled = $input['status'] === 'disabled';
        $recordId = (int) $this->backend->addRecord($zoneId, new RecordSpec($name, $type, $content, (int) $input['ttl'], $priority, $disabled));
        $this->backend->touchZone($zoneId);
        $profile = DnsZone::query()->where('powerdns_domain_id', $zoneId)->first();
        if ($profile) {
            DnsRecord::query()->create([
                'id' => (string) Str::uuid(),
                'powerdns_record_id' => $recordId,
                'dns_zone_id' => $profile->id,
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'ttl' => (int) $input['ttl'],
                'priority' => $priority,
                'is_active' => ! $disabled,
            ]);
        }

        return $this->row($recordId, $name, $type, $content, (int) $input['ttl'], $priority, $disabled);
    }

    /**
     * Changes a record, which may also move it from zone `$fromZoneId` to `$zoneId`.
     *
     * @param  array{type: string, name: string, content: string, ttl: int|string, priority?: int|string|null, status: string}  $input
     * @return array{id: int, name: string, type: string, content: string, ttl: int, priority: ?int, status: string}
     */
    public function update(int $recordId, int $fromZoneId, int $zoneId, string $zoneDomain, array $input): array
    {
        $this->refuseSecondary($zoneId);
        $this->refuseSecondary($fromZoneId);
        $type = strtoupper((string) $input['type']);
        ['name' => $name, 'content' => $content, 'priority' => $priority] = $this->prepare($zoneId, $zoneDomain, $input, $recordId);
        $disabled = $input['status'] === 'disabled';
        $this->backend->updateRecord($recordId, [
            'zone_id' => $zoneId,
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => (int) $input['ttl'],
            'priority' => $priority,
            'disabled' => $disabled,
        ]);
        $this->backend->touchZone($zoneId);
        if ($fromZoneId !== $zoneId) {
            $this->backend->touchZone($fromZoneId);
        }
        $profile = DnsZone::query()->where('powerdns_domain_id', $zoneId)->first();
        if ($profile) {
            DnsRecord::query()->updateOrCreate(
                ['powerdns_record_id' => $recordId],
                [
                    'id' => DnsRecord::query()->where('powerdns_record_id', $recordId)->value('id') ?: (string) Str::uuid(),
                    'dns_zone_id' => $profile->id,
                    'type' => $type,
                    'name' => $name,
                    'content' => $content,
                    'ttl' => (int) $input['ttl'],
                    'priority' => $priority,
                    'is_active' => ! $disabled,
                ],
            );
        }

        return $this->row($recordId, $name, $type, $content, (int) $input['ttl'], $priority, $disabled);
    }

    public function delete(int $recordId, int $zoneId): void
    {
        $this->refuseSecondary($zoneId);
        $this->backend->deleteRecords([$recordId]);
        $this->backend->touchZone($zoneId);
        DnsRecord::query()->where('powerdns_record_id', $recordId)->delete();
    }

    /**
     * A secondary zone (M7) copies its records from the primary server; a
     * change here would be overwritten by the next transfer.
     */
    public function refuseSecondary(int $zoneId): void
    {
        if (strtoupper((string) DB::connection()->table('domains')->where('id', $zoneId)->value('type')) === 'SLAVE') {
            throw ValidationException::withMessages(['zone_domain' => 'This is a secondary zone: its records come from the primary server. Change them there.']);
        }
    }

    /**
     * The zone's records except SOA, as the backend holds them.
     *
     * @return list<array{id: int, name: string, type: string, content: string, ttl: int, priority: ?int, status: string}>
     */
    public function list(int $zoneId): array
    {
        return DB::connection()->table('records')
            ->where('domain_id', $zoneId)
            ->where('type', '!=', 'SOA')
            ->orderBy('name')->orderBy('type')->orderBy('id')
            ->get()
            ->map(fn ($row): array => $this->row((int) $row->id, (string) $row->name, (string) $row->type, (string) $row->content, (int) $row->ttl, $row->prio !== null ? (int) $row->prio : null, (bool) $row->disabled))
            ->all();
    }

    /** `@`, `www` or a full name, to the full name without the trailing dot. */
    public static function fqdn(string $name, string $zoneDomain): string
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '' || $normalized === '@') {
            return $zoneDomain;
        }

        $normalized = rtrim($normalized, '.');
        if (Str::endsWith($normalized, ".{$zoneDomain}") || $normalized === $zoneDomain) {
            return $normalized;
        }

        return "{$normalized}.{$zoneDomain}";
    }

    /** A full name to `@` or the part before the zone, as the DNS page shows it. */
    public static function relativeName(string $fqdn, string $zoneDomain): string
    {
        $fqdn = strtolower(trim(rtrim($fqdn, '.')));
        if ($fqdn === $zoneDomain) {
            return '@';
        }

        $suffix = ".{$zoneDomain}";
        if (Str::endsWith($fqdn, $suffix)) {
            return (string) Str::beforeLast($fqdn, $suffix);
        }

        return $fqdn;
    }

    /**
     * Checks the name and content, and normalizes the content for its type.
     *
     * @return array{name: string, content: string, priority: ?int}
     *
     * @throws ValidationException
     */
    public function prepare(int $zoneId, string $zoneDomain, array $input, ?int $exceptRecordId = null): array
    {
        $type = strtoupper((string) $input['type']);
        $name = self::fqdn((string) $input['name'], $zoneDomain);

        try {
            $this->content->assertValidName($name);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
        try {
            $normalized = $this->content->normalize($type, (string) $input['content'], isset($input['priority']) ? (int) $input['priority'] : null, $zoneDomain);
            $this->rules->assertNoCnameConflict(DB::connection(), $zoneId, $zoneDomain, $name, $type, $exceptRecordId);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        }

        return ['name' => $name] + $normalized;
    }

    /** @return array{id: int, name: string, type: string, content: string, ttl: int, priority: ?int, status: string} */
    private function row(int $id, string $name, string $type, string $content, int $ttl, ?int $priority, bool $disabled): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => in_array($type, ['MX', 'SRV'], true) ? $priority : null,
            'status' => $disabled ? 'disabled' : 'active',
        ];
    }
}
