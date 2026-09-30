<?php

namespace Tests\Unit;

use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailDnsVerifier;
use Tests\TestCase;

class MailDnsVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.mail.hostname' => 'mail.example.net', 'serverpanel.mail.server_ip' => '203.0.113.5']);
    }

    public function test_a_complete_setup_passes(): void
    {
        $checks = $this->verify([
            'shop.test|MX' => [['target' => 'mail.example.net']],
            'shop.test|TXT' => [['entries' => ['v=spf1 ip4:203.0.113.5 mx ~all']]],
            'default._domainkey.shop.test|TXT' => [['entries' => ['v=DKIM1; k=rsa; p=ABC', 'DEF']]],
            '_dmarc.shop.test|TXT' => [['txt' => 'v=DMARC1; p=none']],
            'mail.example.net|A' => [['ip' => '203.0.113.5']],
        ], 'mail.example.net', 'ABCDEF');

        $this->assertSame(['pass'], array_values(array_unique(array_column($checks, 'status'))));
    }

    public function test_missing_records_and_a_ptr_that_does_not_resolve_back_fail(): void
    {
        $checks = collect($this->verify([
            'shop.test|TXT' => [['txt' => 'v=spf1 include:_spf.google.com ~all'], ['txt' => 'v=spf1 mx ~all']],
            'mail.example.net|A' => [['ip' => '198.51.100.9']],
        ], 'vps123.provider.example', 'ABC'))->keyBy('label');

        $this->assertSame('fail', $checks['MX']['status']);
        $this->assertStringContainsString('exactly one SPF', $checks['SPF']['hint']);
        $this->assertSame('fail', $checks['DKIM']['status']);
        $this->assertSame('warn', $checks['DMARC']['status']);
        $this->assertSame('fail', $checks['Mail host mail.example.net']['status']);
        $this->assertSame('fail', $checks['Reverse DNS (PTR)']['status']);
        $this->assertStringContainsString('mail.example.net', $checks['Reverse DNS (PTR)']['hint']);
    }

    /** @param  array<string, array<int, array<string, mixed>>>  $dns */
    private function verify(array $dns, string $ptr, string $dkimKey): array
    {
        $types = [DNS_MX => 'MX', DNS_TXT => 'TXT', DNS_A => 'A'];
        $lookup = fn (string $name, int $type): array => $dns[$name.'|'.$types[$type]] ?? [];

        return (new MailDnsVerifier(new MailDnsRecords, $lookup, fn () => $ptr))->verify('shop.test', 'default', $dkimKey);
    }
}
