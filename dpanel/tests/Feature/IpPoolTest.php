<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\IpAddress;
use App\Models\MailDomain;
use App\Models\MailIp;
use App\Models\User;
use App\Models\Website;
use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\RecordSpec;
use App\Services\Dns\Backend\ZoneSpec;
use App\Services\EdgeGatewayReloader;
use App\Services\Mail\MailIps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Backlog U5: the IP pool, who may use which address, and what moving a site rewrites. */
class IpPoolTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    /** @var list<array{0: string, 1: list<string>}> */
    public array $reloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        $this->session = str_repeat('d', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
        $test = $this;
        $this->app->instance(EdgeGatewayReloader::class, new class($test) extends EdgeGatewayReloader
        {
            public function __construct(private readonly IpPoolTest $test) {}

            public function reload(): bool
            {
                $this->test->reloads[] = ['all', []];

                return true;
            }

            public function reloadDomains(array $domains): bool
            {
                $this->test->reloads[] = ['domains', array_values($domains)];

                return true;
            }
        });
        // Two addresses on the server: a public one and a private one behind NAT.
        Http::fake(['drust.test/system/addresses' => Http::response(['success' => true, 'addresses' => [
            ['interface' => 'eth0', 'address' => '203.0.113.10', 'prefix' => 24, 'family' => 'ipv4', 'public' => true],
            ['interface' => 'eth0', 'address' => '10.0.0.6', 'prefix' => 24, 'family' => 'ipv4', 'public' => false],
            ['interface' => 'eth0', 'address' => '2001:db8::7', 'prefix' => 64, 'family' => 'ipv6', 'public' => true],
        ]])]);
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, ['token' => $this->session, ...$params]);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create(['role' => $role, ...$more]);
    }

    private function website(string $domain, array $more = []): Website
    {
        $ip = $more['ip_address_id'] ?? null;
        unset($more['ip_address_id']);
        $site = Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'root_path' => '/home/u/'.$domain, 'site_owner' => 'u',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary', ...$more,
        ]);

        if ($ip) {
            $site->forceFill(['ip_address_id' => $ip])->save();
        }

        return $site;
    }

    private function pool(): array
    {
        $main = IpAddress::query()->create(['address' => '203.0.113.10', 'mode' => 'shared', 'is_main' => true]);
        $nat = IpAddress::query()->create(['address' => '10.0.0.6', 'public_address' => '198.51.100.6', 'mode' => 'shared']);

        return [$main, $nat];
    }

    public function test_the_admin_sees_the_server_addresses_and_adds_them_to_the_pool(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get($this->url('system.ip-addresses.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('System/IpAddresses/Index')
                ->where('isAdmin', true)->has('discovered', 3)->where('discovered.0.in_pool', false)->has('addresses', 0));

        // Only an address the server has.
        $this->actingAs($admin)->post($this->url('system.ip-addresses.store'), ['address' => '192.0.2.99', 'mode' => 'shared'])
            ->assertSessionHasErrors('address');
        // The first one becomes the main IP; a private one gets its NAT address.
        $this->actingAs($admin)->post($this->url('system.ip-addresses.store'), ['address' => '203.0.113.10', 'mode' => 'shared'])->assertSessionHas('success');
        $this->actingAs($admin)->post($this->url('system.ip-addresses.store'), ['address' => '10.0.0.6', 'public_address' => '198.51.100.6', 'mode' => 'shared', 'label' => 'NAT'])->assertSessionHas('success');
        $this->actingAs($admin)->post($this->url('system.ip-addresses.store'), ['address' => '10.0.0.6', 'mode' => 'shared'])->assertSessionHasErrors('address');

        $this->assertTrue(IpAddress::query()->where('address', '203.0.113.10')->value('is_main'));
        $nat = IpAddress::query()->where('address', '10.0.0.6')->first();
        $this->assertFalse($nat->is_main);
        $this->assertSame('198.51.100.6', $nat->dnsAddress());
        $this->assertSame('eth0', $nat->interface);
        // Each change reloads the gateway, which reads the pool's rules.
        $this->assertSame([['all', []], ['all', []]], $this->reloads);

        $this->actingAs($admin)->get($this->url('system.ip-addresses.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('addresses', 2)->where('addresses.0.is_main', true)
                ->where('addresses.1.dns_address', '198.51.100.6')->where('addresses.1.missing', false));
    }

    public function test_dedicating_an_address_checks_the_owner_and_the_websites_on_it(): void
    {
        [, $nat] = $this->pool();
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $customer = $this->user('general', ['reseller_id' => $reseller->id]);
        $stranger = $this->user('general');

        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'dedicated'])->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'dedicated', 'reseller_id' => $customer->id])->assertSessionHasErrors('reseller_id');
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'dedicated', 'reseller_id' => $reseller->id, 'user_id' => $stranger->id])->assertSessionHasErrors('user_id');

        // A website of someone else already on it blocks dedicating it.
        $this->website('other.test', ['ip_address_id' => $nat->id, 'assigned_user_id' => $stranger->id]);
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'dedicated', 'user_id' => $customer->id])->assertSessionHasErrors('user_id');
        Website::query()->where('domain', 'other.test')->delete();

        // A customer's address stays visible to their reseller.
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'dedicated', 'user_id' => $customer->id])->assertSessionHas('success');
        $this->assertSame([$reseller->id, $customer->id], [$nat->fresh()->reseller_id, $nat->fresh()->user_id]);

        // The main IP stays shared.
        $main = IpAddress::query()->where('is_main', true)->first();
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $main->id]), ['mode' => 'dedicated', 'user_id' => $customer->id])->assertSessionHasErrors('mode');
        $this->actingAs($admin)->post($this->url('system.ip-addresses.main', ['id' => $nat->id]))->assertSessionHasErrors('address');
    }

    public function test_a_reseller_passes_their_addresses_to_their_own_customers_only(): void
    {
        [$main, $nat] = $this->pool();
        $reseller = $this->user('reseller');
        $customer = $this->user('general', ['reseller_id' => $reseller->id]);
        $otherCustomer = $this->user('general', ['reseller_id' => $this->user('reseller')->id]);
        $nat->update(['mode' => 'dedicated', 'reseller_id' => $reseller->id]);

        $this->actingAs($reseller)->get($this->url('system.ip-addresses.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('isAdmin', false)->where('discovered', null)
                ->has('addresses', 1)->where('addresses.0.address', '10.0.0.6')->has('owners.users', 1)->where('owners.users.0.id', $customer->id));

        $this->actingAs($reseller)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['user_id' => $otherCustomer->id])->assertSessionHasErrors('user_id');
        $this->actingAs($reseller)->patch($this->url('system.ip-addresses.update', ['id' => $main->id]), ['user_id' => $customer->id])->assertNotFound();
        $this->actingAs($reseller)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['user_id' => $customer->id, 'mode' => 'shared'])->assertSessionHas('success');
        $this->assertSame(['dedicated', $reseller->id, $customer->id], [$nat->fresh()->mode, $nat->fresh()->reseller_id, $nat->fresh()->user_id]);

        // Adding, removing and the main IP are the admin's.
        $this->actingAs($reseller)->post($this->url('system.ip-addresses.store'), ['address' => '2001:db8::7', 'mode' => 'shared'])->assertForbidden();
        $this->actingAs($reseller)->delete($this->url('system.ip-addresses.destroy', ['id' => $nat->id]))->assertForbidden();
        $this->actingAs($customer)->get($this->url('system.ip-addresses.index'))->assertForbidden();
    }

    public function test_customers_only_pick_shared_addresses_or_their_own(): void
    {
        [$main, $nat] = $this->pool();
        $reseller = $this->user('reseller');
        $customer = $this->user('general', ['reseller_id' => $reseller->id]);
        $other = $this->user('general', ['reseller_id' => $reseller->id]);
        $mine = IpAddress::query()->create(['address' => '203.0.113.11', 'mode' => 'dedicated', 'reseller_id' => $reseller->id, 'user_id' => $customer->id]);
        $theirs = IpAddress::query()->create(['address' => '203.0.113.12', 'mode' => 'dedicated', 'reseller_id' => $reseller->id, 'user_id' => $other->id]);
        $resellerPool = IpAddress::query()->create(['address' => '203.0.113.13', 'mode' => 'dedicated', 'reseller_id' => $reseller->id]);
        $site = $this->website('shop.test', ['assigned_user_id' => $customer->id, 'assigned_reseller_id' => $reseller->id]);

        $this->actingAs($customer)->getJson($this->url('websites.ip.show', ['id' => $site->id]))->assertOk()
            ->assertJsonPath('current.address', '203.0.113.10')->assertJsonPath('current.is_main', true)
            ->assertJsonPath('choices.*.address', ['203.0.113.10', '198.51.100.6', '203.0.113.11']);

        $this->actingAs($customer)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $theirs->id])->assertUnprocessable();
        $this->actingAs($customer)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $resellerPool->id])->assertUnprocessable();
        $this->actingAs($customer)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $mine->id])->assertOk()
            ->assertJsonPath('current.address', '203.0.113.11');
        $this->assertSame($mine->id, $site->fresh()->ip_address_id);
        // Saving the site reloads the gateway for its domain.
        $this->assertContains(['domains', ['shop.test']], $this->reloads);

        // The reseller may put their customer's site on the reseller's own pool; an admin may too.
        $this->actingAs($reseller)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $resellerPool->id])->assertOk();
        $this->actingAs($this->user('admin'))->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $theirs->id])->assertUnprocessable();

        // Back to the main IP is stored as "follows the main IP".
        $this->actingAs($customer)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $main->id])->assertOk();
        $this->assertNull($site->fresh()->ip_address_id);

        // Another customer's website is not even visible.
        $this->actingAs($other)->getJson($this->url('websites.ip.show', ['id' => $site->id]))->assertNotFound();
    }

    public function test_moving_a_site_rewrites_its_records_and_leaves_mail_and_other_sites_alone(): void
    {
        [, $nat] = $this->pool();
        $dns = app(DnsBackend::class);
        $zone = $dns->upsertZone(new ZoneSpec('shop.test', 'native'));
        foreach ([
            ['shop.test', 'A', '203.0.113.10'], ['www.shop.test', 'A', '203.0.113.10'], ['ftp.shop.test', 'A', '203.0.113.10'],
            ['mail.shop.test', 'A', '203.0.113.10'], ['shop.test', 'MX', 'mail.shop.test'], ['blog.shop.test', 'A', '203.0.113.10'],
            ['cdn.shop.test', 'A', '192.0.2.50'], ['shop.test', 'AAAA', '2001:db8::1'],
        ] as [$name, $type, $content]) {
            $dns->addRecord($zone, new RecordSpec($name, $type, $content, 3600, $type === 'MX' ? 10 : 0));
        }
        $admin = $this->user('admin');
        $site = $this->website('shop.test');
        $this->website('www.shop.test', ['type' => 'alias', 'parent_id' => $site->id]);
        $this->website('blog.shop.test', ['type' => 'subdomain', 'parent_id' => $site->id]);

        $response = $this->actingAs($admin)->putJson($this->url('websites.ip.update', ['id' => $site->id]), ['ip_address_id' => $nat->id])->assertOk();

        // DNS gets the NAT (public) address, the gateway keeps the private one.
        $response->assertJsonPath('current.address', '198.51.100.6')->assertJsonPath('dns.zone', 'shop.test');
        $this->assertCount(3, $response->json('dns.changed'));
        $records = DB::table('records')->where('domain_id', $zone)->whereIn('type', ['A', 'AAAA'])->pluck('content', 'name');
        $a = fn (string $name) => DB::table('records')->where('domain_id', $zone)->where('type', 'A')->where('name', $name)->value('content');
        $this->assertSame('198.51.100.6', $a('shop.test'));
        $this->assertSame('198.51.100.6', $a('www.shop.test'));
        $this->assertSame('198.51.100.6', $a('ftp.shop.test'));
        $this->assertSame('203.0.113.10', $a('mail.shop.test'), 'mail has its own sending IP');
        $this->assertSame('203.0.113.10', $a('blog.shop.test'), 'another website keeps its address');
        $this->assertSame('192.0.2.50', $a('cdn.shop.test'));
        $this->assertNotEmpty($records);
        $this->assertSame('2001:db8::1', DB::table('records')->where('domain_id', $zone)->where('type', 'AAAA')->value('content'));
        // The alias answers on the main site's address and has no picker of its own.
        $alias = Website::query()->where('domain', 'www.shop.test')->first();
        $this->actingAs($admin)->getJson($this->url('websites.ip.show', ['id' => $alias->id]))->assertJsonPath('follows_parent', 'shop.test')
            ->assertJsonPath('current.address', '198.51.100.6');
        $this->actingAs($admin)->putJson($this->url('websites.ip.update', ['id' => $alias->id]), ['ip_address_id' => null])->assertUnprocessable();

        // A site whose zone is elsewhere is still moved, with a note.
        $elsewhere = $this->website('elsewhere.example');
        $this->actingAs($admin)->putJson($this->url('websites.ip.update', ['id' => $elsewhere->id]), ['ip_address_id' => $nat->id])->assertOk()
            ->assertJsonPath('dns.zone', null)->assertJsonPath('dns.changed', []);
    }

    public function test_a_new_main_ip_moves_the_records_of_sites_that_follow_it(): void
    {
        [$main, $nat] = $this->pool();
        $dns = app(DnsBackend::class);
        $zone = $dns->upsertZone(new ZoneSpec('follow.test', 'native'));
        $dns->addRecord($zone, new RecordSpec('follow.test', 'A', '203.0.113.10'));
        $dns->addRecord($zone, new RecordSpec('www.follow.test', 'A', '192.0.2.1'));
        $this->website('follow.test');
        $pinned = $this->website('pinned.test', ['ip_address_id' => $nat->id]);

        $this->actingAs($this->user('admin'))->post($this->url('system.ip-addresses.main', ['id' => $nat->id]))->assertSessionHas('success');

        $this->assertTrue($nat->fresh()->is_main);
        $this->assertFalse($main->fresh()->is_main);
        $this->assertNull($pinned->fresh()->ip_address_id);
        $this->assertSame('198.51.100.6', DB::table('records')->where('name', 'follow.test')->where('type', 'A')->value('content'));
        // Only what held the old address moves.
        $this->assertSame('192.0.2.1', DB::table('records')->where('name', 'www.follow.test')->value('content'));
    }

    public function test_an_address_in_use_or_the_main_one_is_not_removed(): void
    {
        [$main, $nat] = $this->pool();
        $admin = $this->user('admin');
        $site = $this->website('busy.test', ['ip_address_id' => $nat->id]);

        $this->actingAs($admin)->delete($this->url('system.ip-addresses.destroy', ['id' => $nat->id]))->assertSessionHasErrors('address');
        $this->actingAs($admin)->delete($this->url('system.ip-addresses.destroy', ['id' => $main->id]))->assertSessionHasErrors('address');
        $site->forceFill(['ip_address_id' => null])->save();
        $this->actingAs($admin)->delete($this->url('system.ip-addresses.destroy', ['id' => $nat->id]))->assertSessionHas('success');
        $this->assertNull(IpAddress::query()->find($nat->id));
    }

    public function test_the_default_site_of_an_address_must_be_on_it(): void
    {
        [$main, $nat] = $this->pool();
        $admin = $this->user('admin');
        $on = $this->website('on.test', ['ip_address_id' => $nat->id]);
        $off = $this->website('off.test');

        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'shared', 'public_address' => '198.51.100.6', 'default_website_id' => $off->id])->assertSessionHasErrors('default_website_id');
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $nat->id]), ['mode' => 'shared', 'public_address' => '198.51.100.6', 'default_website_id' => $on->id])->assertSessionHas('success');
        $this->actingAs($admin)->patch($this->url('system.ip-addresses.update', ['id' => $main->id]), ['mode' => 'shared', 'default_website_id' => $off->id])->assertSessionHas('success');
        $this->assertSame((string) $on->id, $nat->fresh()->default_website_id);
        $this->assertSame((string) $off->id, $main->fresh()->default_website_id);
    }

    public function test_a_dedicated_address_only_sends_mail_for_its_owner(): void
    {
        $customer = $this->user('general');
        IpAddress::query()->create(['address' => '203.0.113.20', 'mode' => 'dedicated', 'user_id' => $customer->id]);
        $mailIp = MailIp::query()->create(['ip' => '203.0.113.20', 'hostname' => 'mail2.example.com', 'is_default' => false]);
        $this->website('owned.test', ['assigned_user_id' => $customer->id]);
        $this->website('foreign.test', ['assigned_user_id' => $this->user('general')->id]);
        $ips = app(MailIps::class);

        $this->assertStringContainsString('dedicated to another account', (string) $ips->assign('foreign.test', $mailIp->id));
        $this->assertNull(MailDomain::query()->where('domain', 'foreign.test')->value('mail_ip_id'));
        $this->assertNull($ips->assign('owned.test', $mailIp->id));
        $this->assertSame($mailIp->id, MailDomain::query()->where('domain', 'owned.test')->value('mail_ip_id'));
    }

    public function test_drust_without_an_address_listing_still_lets_the_admin_add_one(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['drust.test/system/addresses' => Http::response(['error' => 'unsupported', 'capability' => 'ip_addresses'], 501)]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->get($this->url('system.ip-addresses.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('discovered', null)->where('discoveryError', fn ($error) => str_contains((string) $error, 'not supported')));
        $this->actingAs($admin)->post($this->url('system.ip-addresses.store'), ['address' => '192.0.2.99', 'mode' => 'shared'])->assertSessionHas('success');
    }
}
