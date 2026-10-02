<?php

namespace App\Services\Dns;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Rules that span a whole zone rather than one record: the SOA serial that
 * tells secondaries and resolvers the zone changed, and the RFC 1034 rule
 * that a CNAME cannot share its name with any other record.
 */
class DnsZoneRules
{
    /**
     * The next serial in YYYYMMDDnn form. It never goes backwards: a serial
     * already ahead of today (or not date-shaped) is simply increased.
     */
    public static function nextSerial(int $current, ?\DateTimeInterface $today = null): int
    {
        $base = (int) (($today ?? now())->format('Ymd').'01');

        return max($base, $current + 1);
    }

    public static function serialFromSoa(string $content): int
    {
        $parts = preg_split('/\s+/', trim($content)) ?: [];

        return isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : 0;
    }

    public static function withSerial(string $content, int $serial): string
    {
        $parts = preg_split('/\s+/', trim($content)) ?: [];
        if (count($parts) < 7) {
            return $content;
        }
        $parts[2] = (string) $serial;

        return implode(' ', $parts);
    }

    /** Call after any change to a zone's records. */
    public function bumpSerial(ConnectionInterface $db, int $zoneId): void
    {
        $soa = $db->table('records')->where('domain_id', $zoneId)->where('type', 'SOA')->first(['id', 'content']);
        if (! $soa) {
            return;
        }
        $content = (string) $soa->content;
        $db->table('records')->where('id', $soa->id)->update([
            'content' => self::withSerial($content, self::nextSerial(self::serialFromSoa($content))),
        ]);
    }

    /**
     * @throws InvalidArgumentException when the record would break the CNAME rule
     */
    public function assertNoCnameConflict(ConnectionInterface $db, int $zoneId, string $zoneDomain, string $name, string $type, ?int $exceptRecordId = null): void
    {
        $type = strtoupper($type);
        if ($type === 'CNAME' && $name === $zoneDomain) {
            throw new InvalidArgumentException('A CNAME cannot be used at the zone apex (@) because it would hide the SOA and NS records. Use an A or AAAA record instead.');
        }

        $others = $db->table('records')
            ->where('domain_id', $zoneId)
            ->where('name', $name)
            ->when($exceptRecordId, fn ($query) => $query->where('id', '!=', $exceptRecordId))
            ->pluck('type')
            ->map(fn ($value): string => strtoupper((string) $value));

        if ($type === 'CNAME' && $others->isNotEmpty()) {
            throw new InvalidArgumentException("{$name} already has {$others->unique()->implode(', ')} records. A CNAME must be the only record at its name.");
        }
        if ($type !== 'CNAME' && $others->contains('CNAME')) {
            throw new InvalidArgumentException("{$name} is a CNAME. Remove the CNAME before adding a {$type} record at the same name.");
        }
    }
}
