<?php

namespace Tests\Feature;

use App\Models\DnsZone;
use App\Models\User;
use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\RecordSpec;
use App\Services\Dns\Backend\ZoneSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog M6: signing a zone through drust, and keeping a signed zone rectified. */
class DnssecTest extends TestCase
{
    use RefreshDatabase;

    private const DS = 'shop.test. IN DS 23119 13 2 eb250a04dbdf05749cae1d171554c6e84063e0f675fcd31076d2d7b98710bf7b';

    private string $token;

    private DnsBackend $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('e', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        $this->dns = app(DnsBackend::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    /** @return array{0: DnsZone, 1: int} */
    private function zone(User $owner, string $domain = 'shop.test'): array
    {
        $id = $this->dns->upsertZone(new ZoneSpec($domain, 'native'));
        DB::table('records')->insert(['domain_id' => $id, 'name' => $domain, 'type' => 'SOA', 'content' => "ns1.{$domain} hostmaster.{$domain} 2026010101 10800 3600 604800 3600", 'ttl' => 3600, 'disabled' => 0, 'auth' => 1]);
        $profile = DnsZone::create([
            'id' => (string) Str::uuid(), 'powerdns_domain_id' => $id, 'domain' => $domain,
            'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'status' => 'active',
        ]);

        return [$profile, $id];
    }

    private function sign(int $domainId): void
    {
        DB::table('cryptokeys')->insert(['domain_id' => $domainId, 'flags' => 257, 'active' => 1, 'published' => 1, 'content' => 'Private-key-format: v1.2']);
    }

    private function url(DnsZone $zone): string
    {
        return "/cpsess{$this->token}/dns/zones/{$zone->id}/dnssec";
    }

    public function test_turning_it_on_asks_drust_and_shows_the_ds_records(): void
    {
        Http::fake(['drust.test/api/v1/dns/dnssec/shop.test' => Http::response(['success' => true, 'enabled' => true, 'ds' => [self::DS]])]);
        $owner = $this->user('general');
        [$zone] = $this->zone($owner);

        $this->as($owner)->putJson($this->url($zone), ['enabled' => true])
            ->assertOk()
            ->assertExactJson(['supported' => true, 'enabled' => true, 'ds' => [self::DS]]);

        $this->assertTrue($zone->fresh()->dnssec_enabled);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['enabled'] === true
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_the_status_follows_what_drust_reports(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'enabled' => false, 'ds' => []])]);
        $owner = $this->user('general');
        [$zone] = $this->zone($owner);
        $zone->forceFill(['dnssec_enabled' => true])->save();

        $this->as($owner)->getJson($this->url($zone))->assertOk()->assertJson(['enabled' => false, 'ds' => []]);
        $this->assertFalse($zone->fresh()->dnssec_enabled);
    }

    public function test_a_server_that_cannot_sign_says_so_and_changes_nothing(): void
    {
        Http::fake(['drust.test/*' => Http::response(['error' => 'unsupported', 'capability' => 'dns'], 501)]);
        $owner = $this->user('general');
        [$zone] = $this->zone($owner);

        $this->as($owner)->putJson($this->url($zone), ['enabled' => true])->assertStatus(501)->assertJson(['supported' => false]);
        $this->assertFalse($zone->fresh()->dnssec_enabled);
    }

    public function test_a_drust_failure_is_reported_and_changes_nothing(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => false, 'message' => 'Database connection failed'], 400)]);
        $owner = $this->user('general');
        [$zone] = $this->zone($owner);

        $this->as($owner)->putJson($this->url($zone), ['enabled' => true])->assertStatus(502)->assertJsonFragment(['message' => 'DNSSEC could not be changed: DNSSEC for shop.test: Database connection failed']);
        $this->assertFalse($zone->fresh()->dnssec_enabled);
    }

    public function test_someone_elses_zone_is_not_found(): void
    {
        Http::fake();
        [$zone] = $this->zone($this->user('general'));

        $this->as($this->user('general'))->putJson($this->url($zone), ['enabled' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_signed_zone_is_rectified_after_its_records_change_and_an_unsigned_one_is_not(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'message' => 'Zone rectified.'])]);
        $owner = $this->user('general');
        [, $signed] = $this->zone($owner, 'shop.test');
        [, $plain] = $this->zone($owner, 'plain.test');
        $this->sign($signed);

        $this->dns->replaceRecords('plain.test', [new RecordSpec('plain.test', 'A', '192.0.2.1')]);
        $this->dns->touchZone($plain);
        Http::assertNothingSent();

        $this->dns->replaceRecords('shop.test', [new RecordSpec('a.b.shop.test', 'A', '192.0.2.1')]);
        $this->dns->addRecord($signed, new RecordSpec('www.shop.test', 'A', '192.0.2.2'));
        $this->dns->touchZone($signed);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://drust.test/api/v1/dns/dnssec/shop.test/rectify');
    }

    public function test_a_failed_rectify_does_not_undo_the_change(): void
    {
        Http::fake(['drust.test/*' => Http::response('down', 500)]);
        [, $id] = $this->zone($this->user('general'));
        $this->sign($id);

        $this->dns->replaceRecords('shop.test', [new RecordSpec('shop.test', 'A', '192.0.2.7')]);

        $this->assertSame('192.0.2.7', DB::table('records')->where('domain_id', $id)->where('type', 'A')->value('content'));
    }

    public function test_deleting_a_zone_removes_its_keys_and_metadata(): void
    {
        [, $id] = $this->zone($this->user('general'));
        $this->sign($id);
        DB::table('domainmetadata')->insert(['domain_id' => $id, 'kind' => 'NSEC3PARAM', 'content' => '1 0 0 -']);

        $this->dns->deleteZone('shop.test');

        $this->assertSame(0, DB::table('cryptokeys')->where('domain_id', $id)->count());
        $this->assertSame(0, DB::table('domainmetadata')->where('domain_id', $id)->count());
    }
}
