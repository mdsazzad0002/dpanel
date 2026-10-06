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
        config(['serverpanel.mail.hostname' => 'mail.example.net', 'serverpanel.mail.server_ip' => '203.0.113.5']);
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

    /** @param  array<string, array<int, string>>  $dns */
    private function verify(array $dns, string $dkimKey): array
    {
        $fake = new FakeDns($dns);
        $this->app->instance(PublicDnsLookup::class, $fake);

        return (new MailDnsVerifier(new MailDnsRecords, $fake))->verify('shop.test', 'default', $dkimKey);
    }
}
