<?php

namespace Tests\Feature;

use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\PowerDnsBackend;
use App\Services\Dns\Backend\RecordSpec;
use App\Services\Dns\Backend\UnsupportedDnsFeature;
use App\Services\Dns\Backend\ZoneSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Backlog I3: zones and records reach PowerDNS only through DnsBackend. */
class PowerDnsBackendTest extends TestCase
{
    use RefreshDatabase;

    private DnsBackend $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = app(DnsBackend::class);
    }

    private function soa(int $zoneId, string $zone, int $serial = 2026010101): void
    {
        DB::table('records')->insert(['domain_id' => $zoneId, 'name' => $zone, 'type' => 'SOA', 'content' => "ns1.{$zone} hostmaster.{$zone} {$serial} 10800 3600 604800 3600", 'ttl' => 3600, 'disabled' => 0, 'auth' => 1]);
    }

    public function test_powerdns_is_the_bound_backend(): void
    {
        $this->assertInstanceOf(PowerDnsBackend::class, $this->dns);
        $this->assertSame('powerdns', $this->dns->name());
    }

    public function test_upsert_creates_once_and_then_only_changes_the_kind(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('Shop.Test.', 'native'));
        $this->assertSame($id, $this->dns->upsertZone(new ZoneSpec('shop.test', 'slave', '192.0.2.1')));

        $row = DB::table('domains')->where('id', $id)->first();
        $this->assertSame('shop.test', $row->name);
        $this->assertSame('SLAVE', $row->type);
        $this->assertSame('192.0.2.1', $row->master);
        $this->assertSame(1, DB::table('domains')->count());
    }

    public function test_replace_records_keeps_the_soa_moves_its_serial_and_refuses_other_zones(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        $this->soa($id, 'shop.test');
        DB::table('records')->insert(['domain_id' => $id, 'name' => 'old.shop.test', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'disabled' => 0, 'auth' => 1]);

        $this->dns->replaceRecords('shop.test', [
            new RecordSpec('shop.test', 'A', '192.0.2.10'),
            new RecordSpec('shop.test', 'MX', 'mail.shop.test', 3600, 10),
            new RecordSpec('www.shop.test', 'CNAME', 'shop.test', 30),
        ]);

        $records = DB::table('records')->where('domain_id', $id)->orderBy('type')->get();
        $this->assertSame(['A', 'CNAME', 'MX', 'SOA'], $records->pluck('type')->all());
        $this->assertSame(60, (int) $records->firstWhere('type', 'CNAME')->ttl, 'a TTL under a minute is raised');
        $this->assertSame(10, (int) $records->firstWhere('type', 'MX')->prio);
        $this->assertStringNotContainsString(' 2026010101 ', (string) $records->firstWhere('type', 'SOA')->content);

        $this->expectException(\InvalidArgumentException::class);
        $this->dns->replaceRecords('shop.test', [new RecordSpec('evil.test', 'A', '192.0.2.1')]);
    }

    public function test_a_refused_replace_changes_nothing(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        DB::table('records')->insert(['domain_id' => $id, 'name' => 'keep.shop.test', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'disabled' => 0, 'auth' => 1]);

        try {
            $this->dns->replaceRecords('shop.test', [new RecordSpec('a.shop.test', 'A', '192.0.2.1'), new RecordSpec('x.other.test', 'A', '192.0.2.2')]);
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(['keep.shop.test'], DB::table('records')->where('domain_id', $id)->pluck('name')->all());
    }

    public function test_rename_moves_record_names_but_not_lookalikes(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        foreach (['shop.test', 'www.shop.test', 'myshop.test'] as $name) {
            DB::table('records')->insert(['domain_id' => $id, 'name' => $name, 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'disabled' => 0, 'auth' => 1]);
        }

        $this->dns->renameZone('shop.test', 'store.test');

        $this->assertSame('store.test', DB::table('domains')->where('id', $id)->value('name'));
        $this->assertEqualsCanonicalizing(['store.test', 'www.store.test', 'myshop.test'], DB::table('records')->pluck('name')->all());
    }

    public function test_delete_takes_the_records_too_and_twice_is_fine(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        $this->soa($id, 'shop.test');

        $this->dns->deleteZone('shop.test');
        $this->dns->deleteZone('shop.test');

        $this->assertSame(0, DB::table('domains')->count());
        $this->assertSame(0, DB::table('records')->count());
    }

    public function test_dnssec_says_it_is_not_there_yet(): void
    {
        $this->expectException(UnsupportedDnsFeature::class);
        $this->dns->enableDnssec('shop.test', true);
    }

    public function test_one_record_at_a_time_keeps_its_id_and_only_touch_moves_the_serial(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        $this->soa($id, 'shop.test');

        $recordId = $this->dns->addRecord($id, new RecordSpec('WWW.Shop.Test.', 'cname', 'shop.test', 300));
        $this->assertSame(2026010101, $this->serial($id), 'add alone leaves the serial');

        $this->dns->updateRecord($recordId, ['content' => 'other.test', 'disabled' => true, 'priority' => null]);
        $row = DB::table('records')->where('id', $recordId)->first();
        $this->assertSame(['www.shop.test', 'CNAME', 'other.test', 300, 1], [$row->name, $row->type, $row->content, (int) $row->ttl, (int) $row->disabled]);

        $this->dns->touchZone($id);
        $this->assertGreaterThan(2026010101, $this->serial($id));

        $this->dns->deleteRecords([$recordId, $recordId, 999999]);
        $this->assertSame(0, DB::table('records')->where('id', $recordId)->count());
        $this->assertSame(1, DB::table('records')->where('domain_id', $id)->count(), 'the SOA stays');
    }

    public function test_update_refuses_an_unknown_field(): void
    {
        $id = $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        $recordId = $this->dns->addRecord($id, new RecordSpec('shop.test', 'A', '192.0.2.1'));

        $this->expectException(\InvalidArgumentException::class);
        $this->dns->updateRecord($recordId, ['auth' => 0]);
    }

    private function serial(int $zoneId): int
    {
        $soa = (string) DB::table('records')->where('domain_id', $zoneId)->where('type', 'SOA')->value('content');

        return (int) explode(' ', $soa)[2];
    }
}
