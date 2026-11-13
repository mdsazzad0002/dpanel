<?php

namespace Tests\Feature;

use App\Models\DnsZone;
use App\Models\User;
use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\ZoneSpec;
use App\Services\Dns\DnsSecondaryServers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog M7: secondaries copy this server's zones; this server copies others'. */
class DnsZoneTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private DnsBackend $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('f', 64);
        URL::defaults(['token' => $this->token]);
        $this->dns = app(DnsBackend::class);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);

        return $user;
    }

    private function url(string $path): string
    {
        return "/dpt{$this->token}/dns/{$path}";
    }

    /** @return array<string, list<string>> kind => contents */
    private function metadata(string $zone): array
    {
        $id = DB::table('domains')->where('name', $zone)->value('id');

        return DB::table('domainmetadata')->where('domain_id', $id)->orderBy('id')->get()
            ->groupBy('kind')->map(fn ($rows) => $rows->pluck('content')->all())->all();
    }

    private function type(string $zone): string
    {
        return (string) DB::table('domains')->where('name', $zone)->value('type');
    }

    public function test_a_secondary_may_copy_every_zone_and_is_notified(): void
    {
        $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        $this->dns->upsertZone(new ZoneSpec('copied.test', 'slave', '192.0.2.1'));
        $this->as('admin');

        $this->post($this->url('transfers'), ['address' => ' 2001:DB8::53 ', 'label' => 'ns2'])->assertRedirect()->assertSessionHas('success');
        $this->post($this->url('transfers'), ['address' => '203.0.113.53'])->assertRedirect();

        $this->assertSame('MASTER', $this->type('shop.test'));
        $this->assertSame(['ALLOW-AXFR-FROM' => ['2001:db8::53', '203.0.113.53'], 'ALSO-NOTIFY' => ['2001:db8::53', '203.0.113.53']], $this->metadata('shop.test'));
        // A zone this server copies is not passed on.
        $this->assertSame('SLAVE', $this->type('copied.test'));
        $this->assertSame([], $this->metadata('copied.test'));

        // A zone made later gets the same list.
        $this->dns->upsertZone(new ZoneSpec('new.test', 'native'));
        $this->assertSame('MASTER', $this->type('new.test'));
        $this->assertSame(['2001:db8::53', '203.0.113.53'], $this->metadata('new.test')['ALSO-NOTIFY']);

        // Removing the last one takes every grant away and the zones back to NATIVE.
        foreach (app(DnsSecondaryServers::class)->all() as $secondary) {
            $this->delete($this->url("transfers/{$secondary['id']}"))->assertRedirect();
        }
        $this->assertSame('NATIVE', $this->type('shop.test'));
        $this->assertSame([], $this->metadata('shop.test'));
    }

    public function test_only_addresses_are_accepted_once_each(): void
    {
        $this->as('admin');

        $this->post($this->url('transfers'), ['address' => 'ns2.example.com'])->assertSessionHasErrors('address');
        $this->assertSame([], app(DnsSecondaryServers::class)->all());
        $this->flushSession();
        $this->withSession(['panel_session_token' => $this->token]);
        $this->post($this->url('transfers'), ['address' => '203.0.113.53'])->assertSessionHasNoErrors();
        $this->post($this->url('transfers'), ['address' => '203.0.113.53'])->assertSessionHasErrors('address');
        $this->assertCount(1, app(DnsSecondaryServers::class)->all());
    }

    public function test_only_an_admin_manages_secondaries(): void
    {
        $this->as('reseller');

        $this->get($this->url('transfers'))->assertForbidden();
        $this->post($this->url('transfers'), ['address' => '203.0.113.53'])->assertForbidden();
        $this->assertSame([], app(DnsSecondaryServers::class)->all());
    }

    public function test_the_page_lists_secondaries_and_copied_zones(): void
    {
        $this->dns->upsertZone(new ZoneSpec('copied.test', 'slave', '192.0.2.1'));
        $this->dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        app(DnsSecondaryServers::class)->add('203.0.113.53', 'ns2');
        $this->as('admin');
        $this->withoutVite();

        $this->get($this->url('transfers'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dns/DnsTransfers')
            ->where('secondaries.0.address', '203.0.113.53')
            ->where('primaryZones', 1)
            ->where('secondaryZones.0.name', 'copied.test')
            ->where('secondaryZones.0.primaries', '192.0.2.1'));
    }

    private function zoneForm(array $extra = []): array
    {
        return $extra + ['domain' => 'copied.test', 'type' => 'slave', 'primaries' => '192.0.2.1, 2001:db8::1',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active'];
    }

    public function test_a_secondary_zone_takes_its_primaries_and_no_local_records(): void
    {
        $this->as('admin');

        $this->post($this->url('zones'), $this->zoneForm())->assertSessionHasNoErrors();

        $row = DB::table('domains')->where('name', 'copied.test')->first();
        $this->assertSame('SLAVE', $row->type);
        $this->assertSame('192.0.2.1,2001:db8::1', $row->master);
        // No SOA here: its serial could be higher than the primary's and stop the copy.
        $this->assertSame(0, DB::table('records')->where('domain_id', $row->id)->count());
        $this->assertTrue(DnsZone::query()->where('domain', 'copied.test')->exists());
    }

    public function test_a_secondary_zone_needs_valid_primaries_and_an_admin(): void
    {
        $this->as('admin');
        $this->post($this->url('zones'), $this->zoneForm(['primaries' => '']))->assertSessionHasErrors('primaries');
        $this->post($this->url('zones'), $this->zoneForm(['primaries' => 'primary.example.com']))->assertSessionHasErrors('primaries');

        $this->as('reseller');
        $this->post($this->url('zones'), $this->zoneForm())->assertSessionHasErrors('type');
        $this->assertFalse(DB::table('domains')->where('name', 'copied.test')->exists());
    }

    public function test_records_of_a_secondary_zone_cannot_be_changed_here(): void
    {
        $admin = $this->as('admin');
        $id = $this->dns->upsertZone(new ZoneSpec('copied.test', 'slave', '192.0.2.1'));
        DnsZone::create(['id' => (string) Str::uuid(), 'powerdns_domain_id' => $id, 'domain' => 'copied.test', 'owner_user_id' => $admin->id, 'created_by_user_id' => $admin->id, 'status' => 'active']);
        $recordId = DB::table('records')->insertGetId(['domain_id' => $id, 'name' => 'www.copied.test', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'disabled' => 0, 'auth' => 1]);

        $this->post($this->url('records'), ['zone_domain' => 'copied.test', 'type' => 'A', 'name' => 'api', 'content' => '192.0.2.8', 'ttl' => 300, 'status' => 'active'])
            ->assertSessionHasErrors('zone_domain');
        $this->delete($this->url("records/{$recordId}"))->assertSessionHasErrors('zone_domain');

        $this->assertSame(1, DB::table('records')->where('domain_id', $id)->count());
    }

    public function test_turning_a_zone_into_a_secondary_empties_it_for_the_copy(): void
    {
        $admin = $this->as('admin');
        $this->post($this->url('zones'), $this->zoneForm(['type' => 'master', 'primaries' => '']))->assertSessionHasNoErrors();
        $id = (int) DB::table('domains')->where('name', 'copied.test')->value('id');
        $this->assertGreaterThan(0, DB::table('records')->where('domain_id', $id)->count());
        $profile = DnsZone::query()->where('domain', 'copied.test')->firstOrFail();

        $this->patch($this->url("zones/{$profile->id}"), $this->zoneForm(['email' => 'hostmaster@copied.test']))->assertSessionHasNoErrors();

        $this->assertSame('SLAVE', $this->type('copied.test'));
        $this->assertSame(0, DB::table('records')->where('domain_id', $id)->count());
    }
}
