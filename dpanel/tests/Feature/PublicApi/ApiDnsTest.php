<?php

namespace Tests\Feature\PublicApi;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Backlog U1: DNS records through /api/v1, only in the owner's zones. */
class ApiDnsTest extends PublicApiTestCase
{
    private function zone(User $owner, string $domain): DnsZone
    {
        $id = DB::table('domains')->insertGetId(['name' => $domain, 'type' => 'NATIVE']);
        DB::table('records')->insert([
            'domain_id' => $id, 'name' => $domain, 'type' => 'SOA', 'ttl' => 3600, 'disabled' => 0,
            'content' => "ns1.panel.test hostmaster.{$domain} 2026010101 3600 600 1209600 3600",
        ]);

        return DnsZone::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'powerdns_domain_id' => $id,
            'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'status' => 'active',
        ]);
    }

    private function serial(DnsZone $zone): int
    {
        return (int) explode(' ', (string) DB::table('records')->where('domain_id', $zone->powerdns_domain_id)->where('type', 'SOA')->value('content'))[2];
    }

    public function test_zones_are_listed_for_their_owner_only(): void
    {
        $customer = $this->user();
        $this->zone($customer, 'mine.test');
        $this->zone($this->user(), 'theirs.test');

        $this->api($this->token($customer, ['dns:read']), 'GET', '/dns/zones')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', 'mine.test');
        $this->assertCount(2, $this->api($this->token($this->user('admin'), ['dns:read']), 'GET', '/dns/zones')->json('data'));
    }

    public function test_a_secondary_zone_is_read_only_here_as_on_the_dns_page(): void
    {
        $customer = $this->user();
        $zone = $this->zone($customer, 'copied.test');
        DB::table('domains')->where('id', $zone->powerdns_domain_id)->update(['type' => 'SLAVE', 'master' => '203.0.113.10']);
        $recordId = DB::table('records')->insertGetId(['domain_id' => $zone->powerdns_domain_id, 'name' => 'www.copied.test', 'type' => 'A', 'content' => '203.0.113.20', 'ttl' => 300, 'disabled' => 0]);
        $plain = $this->token($customer, ['dns:write']);

        $this->api($plain, 'POST', '/dns/zones/copied.test/records', ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.30', 'ttl' => 300])
            ->assertUnprocessable()->assertJsonPath('errors.zone_domain.0', fn ($message) => str_contains($message, 'secondary zone'));
        $this->api($plain, 'DELETE', "/dns/zones/copied.test/records/{$recordId}")->assertUnprocessable();
        $this->assertSame(2, DB::table('records')->where('domain_id', $zone->powerdns_domain_id)->count());
    }

    public function test_records_are_added_changed_and_deleted(): void
    {
        $customer = $this->user();
        $zone = $this->zone($customer, 'mine.test');
        $serial = $this->serial($zone);
        $plain = $this->token($customer, ['dns:write']);

        $created = $this->api($plain, 'POST', '/dns/zones/mine.test/records', ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 mx -all', 'ttl' => 300])
            ->assertCreated()
            ->assertJsonPath('data.name', '@')
            ->assertJsonPath('data.fqdn', 'mine.test')
            ->assertJsonPath('data.content', '"v=spf1 mx -all"')
            ->assertJsonPath('data.status', 'active');
        $id = $created->json('data.id');
        $this->assertGreaterThan($serial, $this->serial($zone));
        $this->assertSame($id, DnsRecord::query()->sole()->powerdns_record_id);

        $this->api($plain, 'POST', "/dns/zones/{$zone->id}/records", ['type' => 'MX', 'name' => '@', 'content' => 'mail.mine.test', 'ttl' => 300, 'priority' => 10])
            ->assertCreated()->assertJsonPath('data.priority', 10);
        $this->api($plain, 'GET', '/dns/zones/mine.test/records')->assertOk()->assertJsonCount(2, 'data');

        // Only the fields sent change.
        $this->api($plain, 'PUT', "/dns/zones/mine.test/records/{$id}", ['name' => 'www', 'ttl' => 600])
            ->assertOk()
            ->assertJsonPath('data.name', 'www')
            ->assertJsonPath('data.ttl', 600)
            ->assertJsonPath('data.content', '"v=spf1 mx -all"');
        $this->assertSame('www.mine.test', DB::table('records')->where('id', $id)->value('name'));

        $this->api($plain, 'DELETE', "/dns/zones/mine.test/records/{$id}")->assertOk();
        $this->assertFalse(DB::table('records')->where('id', $id)->exists());
        $this->assertSame(0, DnsRecord::query()->where('powerdns_record_id', $id)->count());
    }

    public function test_another_users_zone_and_records_are_not_found(): void
    {
        $customer = $this->user();
        $this->zone($customer, 'mine.test');
        $theirs = $this->zone($this->user(), 'theirs.test');
        $theirRecord = DB::table('records')->insertGetId(['domain_id' => $theirs->powerdns_domain_id, 'name' => 'theirs.test', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'disabled' => 0]);
        $soa = DB::table('records')->where('domain_id', $theirs->powerdns_domain_id)->where('type', 'SOA')->value('id');
        $plain = $this->token($customer, ['dns:write']);

        $this->api($plain, 'GET', '/dns/zones/theirs.test/records')->assertNotFound();
        $this->api($plain, 'POST', '/dns/zones/theirs.test/records', ['type' => 'A', 'name' => '@', 'content' => '192.0.2.9', 'ttl' => 300])->assertNotFound();
        // A record id from another zone is not found through one's own zone.
        $this->api($plain, 'PUT', "/dns/zones/mine.test/records/{$theirRecord}", ['content' => '192.0.2.9'])->assertNotFound();
        $this->api($plain, 'DELETE', "/dns/zones/mine.test/records/{$theirRecord}")->assertNotFound();
        $this->assertSame('192.0.2.1', DB::table('records')->where('id', $theirRecord)->value('content'));

        // The zone's own SOA is never edited as a record.
        $mine = $this->token($this->user('admin'), ['dns:write']);
        $this->api($mine, 'DELETE', "/dns/zones/theirs.test/records/{$soa}")->assertNotFound();
    }

    public function test_invalid_records_are_json_422(): void
    {
        $customer = $this->user();
        $this->zone($customer, 'mine.test');
        DB::table('records')->insert(['domain_id' => DnsZone::query()->sole()->powerdns_domain_id, 'name' => 'www.mine.test', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'disabled' => 0]);
        $plain = $this->token($customer, ['dns:write']);

        $this->api($plain, 'POST', '/dns/zones/mine.test/records', ['type' => 'A', 'name' => '@', 'content' => 'not-an-ip', 'ttl' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->api($plain, 'POST', '/dns/zones/mine.test/records', ['type' => 'SOA', 'name' => '@', 'content' => 'x', 'ttl' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->api($plain, 'POST', '/dns/zones/mine.test/records', ['type' => 'CNAME', 'name' => 'www', 'content' => 'other.test', 'ttl' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
    }
}
