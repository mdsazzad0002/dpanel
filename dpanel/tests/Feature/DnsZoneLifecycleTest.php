<?php

namespace Tests\Feature;

use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DnsZoneLifecycleTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('powerdns_domain_id')->nullable()->unique();
            $table->string('domain')->unique();
            $table->uuid('website_id')->nullable();
            $table->string('server_id', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('transferred_by_user_id')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->string('source', 32)->default('standalone');
            $table->string('provider', 32)->default('powerdns');
            $table->string('mode', 32)->default('authoritative');
            $table->boolean('dnssec_enabled')->default(false);
            $table->boolean('proxy_enabled')->default(false);
            $table->boolean('logging_enabled')->default(false);
            $table->boolean('analytics_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('dns_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('powerdns_record_id')->nullable()->unique();
            $table->uuid('dns_zone_id');
            $table->string('type', 16);
            $table->string('name');
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedInteger('priority')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('proxied')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        config(['dns.our_nameservers' => ['ns1.panel.test', 'ns2.panel.test']]);
    }

    public function test_a_zone_can_be_created_filled_and_exported(): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'example.com',
            'type' => 'master',
            'email' => 'hostmaster@example.com',
            'refresh' => 3600,
            'retry' => 600,
            'expire' => 1209600,
            'minimum_ttl' => 3600,
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'DNS zone created.');

        $zone = DnsZone::query()->sole();
        $this->assertSame($this->admin->id, $zone->owner_user_id);
        $soa = DB::table('records')->where('type', 'SOA')->sole();
        $this->assertStringStartsWith('ns1.panel.test hostmaster.example.com '.now()->format('Ymd'), $soa->content);
        // A new zone answers with this server's nameservers straight away.
        $this->assertSame(['ns1.panel.test', 'ns2.panel.test'], DB::table('records')->where('type', 'NS')->where('name', 'example.com')->orderBy('content')->pluck('content')->all());

        $this->request()->post("/cpsess{$this->token}/dns/records", [
            'zone_domain' => 'example.com', 'type' => 'TXT', 'name' => '@',
            'content' => 'v=spf1 mx -all', 'ttl' => 300, 'priority' => null, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame('"v=spf1 mx -all"', DB::table('records')->where('type', 'TXT')->value('content'));
        $this->assertGreaterThan(
            (int) explode(' ', $soa->content)[2],
            (int) explode(' ', DB::table('records')->where('type', 'SOA')->value('content'))[2],
        );

        $this->request()->post("/cpsess{$this->token}/dns/records", [
            'zone_domain' => 'example.com', 'type' => 'CNAME', 'name' => '@',
            'content' => 'other.example.net', 'ttl' => 300, 'priority' => null, 'status' => 'active',
        ])->assertSessionHasErrors('content');

        $this->request()->get("/cpsess{$this->token}/dns/zones/{$zone->id}/export")
            ->assertOk()
            ->assertSee("example.com.\t300\tIN\tTXT\t\"v=spf1 mx -all\"", false);
    }

    public function test_existing_records_are_scanned_and_the_picked_ones_imported(): void
    {
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret', 'dns.our_nameservers' => ['ns1.panel.test']]);
        $public = [
            'NS shop.test' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'A shop.test' => ['203.0.113.10'],
            'MX shop.test' => ['10 mail.shop.test'],
            'TXT shop.test' => ['v=spf1 mx -all'],
            'CNAME www.shop.test' => ['shop.test'],
            'A mail.shop.test' => ['203.0.113.20'],
            'SRV _autodiscover._tcp.shop.test' => ['0 0 443 mail.shop.test'],
        ];
        Http::fake(['drust.test/api/v1/dns/lookup' => function ($request) use ($public) {
            return Http::response(['success' => true, 'data' => ['answers' => array_map(fn ($q) => [
                'name' => $q['name'], 'type' => $q['type'], 'error' => '',
                'values' => $public[$q['type'].' '.$q['name']] ?? [],
            ], $request['queries'])]]);
        }]);
        $this->createZone('shop.test');
        $zone = DnsZone::query()->sole();

        $scan = $this->request()->getJson("/cpsess{$this->token}/dns/zones/{$zone->id}/scan")->assertOk();
        $scan->assertJsonPath('nameservers', ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'])
            ->assertJsonPath('uses_our_nameservers', false)
            ->assertJsonPath('wildcard', false);
        $found = collect($scan->json('records'))->map(fn ($r) => "{$r['type']} {$r['name']} {$r['priority']} {$r['content']}")->sort()->values()->all();
        $this->assertSame([
            'A mail.shop.test  203.0.113.20',
            'A shop.test  203.0.113.10',
            'CNAME www.shop.test  shop.test',
            'MX shop.test 10 mail.shop.test',
            'SRV _autodiscover._tcp.shop.test 0 0 443 mail.shop.test',
            'TXT shop.test  v=spf1 mx -all',
        ], $found);

        $picked = collect($scan->json('records'))->reject(fn ($r) => $r['type'] === 'SRV')
            ->map(fn ($r) => array_intersect_key($r, array_flip(['name', 'type', 'content', 'priority', 'ttl'])))->values()->all();
        $this->request()->post("/cpsess{$this->token}/dns/zones/{$zone->id}/import-records", ['records' => $picked])
            ->assertSessionHas('success', 'Imported 5 records into shop.test.');

        $this->assertSame('shop.test', DB::table('records')->where('type', 'CNAME')->value('content'));
        $this->assertSame(10, (int) DB::table('records')->where('type', 'MX')->value('prio'));
        $this->assertSame('"v=spf1 mx -all"', DB::table('records')->where('type', 'TXT')->value('content'));
        $this->assertFalse(DB::table('records')->where('type', 'SRV')->exists());

        // Scanning again marks what the zone already has.
        $again = $this->request()->getJson("/cpsess{$this->token}/dns/zones/{$zone->id}/scan");
        $this->assertTrue(collect($again->json('records'))->firstWhere('type', 'MX')['exists']);
    }

    public function test_imported_records_must_belong_to_the_zone(): void
    {
        $this->createZone('shop.test');
        $zone = DnsZone::query()->sole();

        $this->request()->post("/cpsess{$this->token}/dns/zones/{$zone->id}/import-records", ['records' => [
            ['name' => 'evil.other.test', 'type' => 'A', 'content' => '203.0.113.9', 'priority' => null, 'ttl' => 300],
        ]])->assertSessionHas('error');
        $this->assertFalse(DB::table('records')->where('name', 'evil.other.test')->exists());
    }

    private function createZone(string $domain): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => $domain, 'type' => 'master', 'email' => "hostmaster@{$domain}",
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHasNoErrors();
    }

    public function test_several_domains_are_added_at_once(): void
    {
        $this->createZone('one.test');
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => "https://Two.test/\nthree.test, one.test\n\n", 'type' => 'master', 'email' => '',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHas('success', 'Created 2 DNS zones. Already existed: one.test.');

        $this->assertSame(['one.test', 'three.test', 'two.test'], DnsZone::query()->orderBy('domain')->pluck('domain')->all());
        $this->assertStringContainsString('hostmaster.two.test', (string) DB::table('records')->where('type', 'SOA')->where('name', 'two.test')->value('content'));

        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'good.test bad_domain', 'type' => 'master', 'email' => '',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHasErrors('domain');
        $this->assertFalse(DnsZone::query()->where('domain', 'good.test')->exists());
    }

    public function test_connection_status_compares_public_nameservers_with_the_zone(): void
    {
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        $public = ['NS here.test' => ['ns2.panel.test', 'ns1.panel.test'], 'NS away.test' => ['ada.ns.cloudflare.com']];
        Http::fake(['drust.test/api/v1/dns/lookup' => fn ($request) => Http::response(['success' => true, 'data' => ['answers' => array_map(fn ($q) => [
            'name' => $q['name'], 'type' => $q['type'], 'error' => '', 'values' => $public[$q['type'].' '.$q['name']] ?? [],
        ], $request['queries'])]])]);
        foreach (['here.test', 'away.test', 'nowhere.test'] as $domain) {
            $this->createZone($domain);
        }

        $zones = $this->request()->getJson("/cpsess{$this->token}/dns/zones/connection")->assertOk()->json('zones');
        $this->assertSame('connected', $zones['here.test']['status']);
        $this->assertSame('pending', $zones['away.test']['status']);
        $this->assertSame(['ns1.panel.test', 'ns2.panel.test'], collect($zones['away.test']['assigned'])->sort()->values()->all());
        $this->assertSame('unregistered', $zones['nowhere.test']['status']);
    }

    public function test_an_invalid_soa_email_is_rejected_with_a_field_error(): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'example.com', 'type' => 'master', 'email' => 'not-an-email',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, DnsZone::query()->count());
    }

    public function test_mail_guide_records_replace_the_old_mail_records_in_a_local_zone(): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'example.com', 'type' => 'master', 'email' => 'hostmaster@example.com',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        foreach ([
            ['MX', '@', 'old-mx.example.net', 10], ['MX', '@', 'backup-mx.example.net', 20],
            ['TXT', '@', 'v=spf1 include:old.example.net -all', null], ['TXT', '@', 'google-site-verification=abc', null],
            ['CNAME', '_dmarc', 'dmarc.example.net', null], ['A', 'www', '203.0.113.9', null],
        ] as [$type, $name, $content, $priority]) {
            $this->request()->post("/cpsess{$this->token}/dns/records", [
                'zone_domain' => 'example.com', 'type' => $type, 'name' => $name,
                'content' => $content, 'ttl' => 300, 'priority' => $priority, 'status' => 'active',
            ])->assertSessionHasNoErrors();
        }

        $writer = app(\App\Services\Mail\MailDnsZoneWriter::class);
        // A subdomain's mail records go into the parent zone.
        $this->assertNotNull($writer->zoneFor($this->admin, 'shop.example.com'));
        $this->assertNull($writer->zoneFor(User::factory()->create(), 'example.com'));

        $records = [
            ['type' => 'MX', 'name' => '@', 'value' => 'mail.panel.test', 'priority' => 10],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 ip4:198.51.100.7 mx ~all', 'priority' => null],
            ['type' => 'TXT', 'name' => 'default._domainkey', 'value' => '', 'priority' => null],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none', 'priority' => null],
        ];
        $result = $writer->apply($writer->zoneFor($this->admin, 'example.com'), 'example.com', $records);

        $this->assertSame(['added' => 0, 'replaced' => 3, 'unchanged' => 0], array_diff_key($result, ['skipped' => true]));
        $this->assertCount(1, $result['skipped']);
        $rows = fn (string $type, string $name) => DB::table('records')->where('type', $type)->where('name', $name)->orderBy('content')->pluck('content')->all();
        $this->assertSame(['mail.panel.test'], $rows('MX', 'example.com'));
        $this->assertSame(['"google-site-verification=abc"', '"v=spf1 ip4:198.51.100.7 mx ~all"'], $rows('TXT', 'example.com'));
        $this->assertSame([], $rows('CNAME', '_dmarc.example.com'));
        $this->assertSame(['"v=DMARC1; p=none"'], $rows('TXT', '_dmarc.example.com'));
        $this->assertSame(['203.0.113.9'], $rows('A', 'www.example.com'));
        // The panel's mirror rows follow: none left pointing at a removed record.
        $this->assertSame([], \App\Models\DnsRecord::query()->whereNotIn('powerdns_record_id', DB::table('records')->pluck('id'))->pluck('name')->all());

        // Running it again changes nothing.
        $again = $writer->apply($writer->zoneFor($this->admin, 'example.com'), 'example.com', $records);
        $this->assertSame(['added' => 0, 'replaced' => 0, 'unchanged' => 3], array_diff_key($again, ['skipped' => true]));
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
