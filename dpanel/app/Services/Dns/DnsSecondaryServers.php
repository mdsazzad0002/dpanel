<?php

namespace App\Services\Dns;

use App\Services\Dns\Backend\DnsBackend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog M7: the nameservers that copy this server's zones. Every zone this
 * server is primary for lets them transfer it (AXFR) and notifies them when
 * its serial changes.
 */
class DnsSecondaryServers
{
    public const MAX = 10;

    private const TABLE = 'dns_secondary_servers';

    /** @return array<int, string> */
    public function addresses(): array
    {
        return array_column($this->all(), 'address');
    }

    /** @return array<int, array{id: int, address: string, label: ?string}> */
    public function all(): array
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [];
            }

            return DB::table(self::TABLE)->orderBy('id')->get()
                ->map(fn ($row) => ['id' => (int) $row->id, 'address' => (string) $row->address, 'label' => $row->label !== null ? (string) $row->label : null])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** A plain IPv4 or IPv6 address, written the one way PowerDNS reads it. */
    public static function normalize(string $address): ?string
    {
        $address = trim($address, " \t\n\r\0\x0B[]");
        $packed = @inet_pton($address);

        return $packed === false ? null : inet_ntop($packed);
    }

    public function add(string $address, ?string $label): void
    {
        DB::table(self::TABLE)->insert(['address' => $address, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function remove(int $id): bool
    {
        return DB::table(self::TABLE)->where('id', $id)->delete() > 0;
    }

    /** Gives every zone the current list. Returns how many zones were set. */
    public function applyToAllZones(DnsBackend $backend): int
    {
        $addresses = $this->addresses();
        $count = 0;
        foreach (DB::table('domains')->whereIn('type', ['NATIVE', 'MASTER'])->pluck('name') as $zone) {
            $backend->setTransfers((string) $zone, $addresses);
            $count++;
        }

        return $count;
    }
}
