<?php

namespace Tests\Unit;

use App\Services\Dns\PublicDnsLookup;
use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailDnsVerifier;
use Tests\Support\FakeDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailDnsVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PublicDnsLookup::class, new FakeDns([]));
        config(['dpanel.mail.hostname' => 'mail.example.net', 'dpanel.mail.server_ip' => '203.0.113.5']);
    }

    public function test_a_complete_setup_passes(): void
    {
        $checks = $this->verify([
            'MX shop.test' => ['mail.example.net'],
            'TXT shop.test' => ['v=spf1 ip4:203.0.113.5 mx ~all'],
            'TXT default._domainkey.shop.test' => ['v=DKIM1; k=rsa; p=ABCDEF'],
            'TXT _dmarc.shop.test' => ['v=DMARC1; p=none'],
            'A mail.example.net' => ['203.0.113.5'],
            'PTR 203.0.113.5' => ['mail.example.net'],
        ], 'ABCDEF');

        $this->assertSame(['pass'], array_values(array_unique(array_column($checks, 'status'))));
    }

    public function test_missing_records_and_a_ptr_that_does_not_resolve_back_fail(): void
    {
        $checks = collect($this->verify([
            'TXT shop.test' => ['v=spf1 include:_spf.google.com ~all', 'v=spf1 mx ~all'],
            'A mail.example.net' => ['198.51.100.9'],
            'PTR 203.0.113.5' => ['vps123.provider.example'],
        ], 'ABC'))->keyBy('label');

        $this->assertSame('fail', $checks['MX']['status']);
        $this->assertStringContainsString('exactly one SPF', $checks['SPF']['hint']);
        $this->assertSame('fail', $checks['DKIM']['status']);
        $this->assertSame('warn', $checks['DMARC']['status']);
        $this->assertSame('fail', $checks['Mail host mail.example.net']['status']);
        $this->assertSame('fail', $checks['Reverse DNS (PTR)']['status']);
        $this->assertStringContainsString('mail.example.net', $checks['Reverse DNS (PTR)']['hint']);
    }

    public function test_a_lookup_without_an_answer_is_not_reported_as_wrong(): void
    {
        $checks = collect($this->verify([
            'MX shop.test' => [],
            'A mail.example.net' => ['203.0.113.5'],
            'failed' => ['MX shop.test'],
        ], 'ABC'))->keyBy('label');

        $this->assertSame('warn', $checks['MX']['status']);
        $this->assertStringContainsString('did not answer', $checks['MX']['hint']);
    }

    public function test_a_server_without_ipv6_is_not_asked_for_ipv6_records(): void
    {
        $keys = array_column($this->verify(['A mail.example.net' => ['203.0.113.5']], 'ABC'), 'key');

        $this->assertNotContains('host6', $keys);
        $this->assertNotContains('ptr6', $keys);
    }

    public function test_a_dual_stack_server_with_both_ptrs_passes(): void
    {
        config(['dpanel.mail.server_ipv6' => '2001:db8::5']);
        $checks = collect($this->verify([
            'MX shop.test' => ['mail.example.net'],
            'TXT shop.test' => ['v=spf1 ip4:203.0.113.5 ip6:2001:db8::5 ~all'],
            'TXT default._domainkey.shop.test' => ['v=DKIM1; k=rsa; p=ABCDEF'],
            'TXT _dmarc.shop.test' => ['v=DMARC1; p=none'],
            'A mail.example.net' => ['203.0.113.5'],
            'AAAA mail.example.net' => ['2001:db8::5'],
            'PTR 203.0.113.5' => ['mail.example.net'],
            'PTR 2001:db8::5' => ['mail.example.net'],
        ], 'ABCDEF'))->keyBy('key');

        $this->assertSame('pass', $checks['host6']['status']);
        $this->assertSame('pass', $checks['ptr6']['status']);
        $this->assertSame('pass', $checks['TXT @']['status']);
    }

    public function test_a_missing_ipv6_ptr_warns_instead_of_failing(): void
    {
        config(['dpanel.mail.server_ipv6' => '2001:db8::5']);
        $checks = collect($this->verify([
            'A mail.example.net' => ['203.0.113.5'],
            'PTR 203.0.113.5' => ['mail.example.net'],
        ], 'ABC'))->keyBy('key');

        // Nothing bounces: the gate keeps outbound mail on IPv4 meanwhile.
        $this->assertSame('warn', $checks['ptr6']['status']);
        $this->assertStringContainsString('IPv4 only', $checks['ptr6']['hint']);
        $this->assertSame('warn', $checks['host6']['status']);
    }

    public function test_an_aaaa_record_pointing_at_another_server_fails(): void
    {
        config(['dpanel.mail.server_ipv6' => '2001:db8::5']);
        $checks = collect($this->verify([
            'A mail.example.net' => ['203.0.113.5'],
            'AAAA mail.example.net' => ['2001:db8:dead::9'],
        ], 'ABC'))->keyBy('key');

        $this->assertSame('fail', $checks['host6']['status']);
    }

    public function test_spf_without_the_ipv6_address_warns(): void
    {
        config(['dpanel.mail.server_ipv6' => '2001:db8::5']);
        $checks = collect($this->verify([
            'TXT shop.test' => ['v=spf1 ip4:203.0.113.5 ~all'],
            'A mail.example.net' => ['203.0.113.5'],
        ], 'ABC'))->keyBy('key');

        $this->assertSame('warn', $checks['TXT @']['status']);
        $this->assertStringContainsString('ip6:2001:db8::5', $checks['TXT @']['hint']);
    }

    /** @param  array<string, array<int, string>>  $dns */
    private function verify(array $dns, string $dkimKey): array
    {
        $fake = new FakeDns($dns);
        $this->app->instance(PublicDnsLookup::class, $fake);

        return (new MailDnsVerifier(new MailDnsRecords, $fake))->verify('shop.test', 'default', $dkimKey);
    }
}
