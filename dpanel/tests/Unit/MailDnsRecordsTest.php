<?php

namespace Tests\Unit;

use App\Services\Mail\MailDnsRecords;
use Tests\TestCase;

class MailDnsRecordsTest extends TestCase
{
    public function test_every_domain_points_mx_at_the_one_mail_host(): void
    {
        config(['serverpanel.mail.hostname' => 'panel.example.com', 'serverpanel.mail.server_ip' => '203.0.113.5']);
        $records = collect((new MailDnsRecords)->records('shop.test', 'default', 'KEY'))->keyBy(fn ($r) => $r['type'].' '.$r['name']);

        $this->assertSame('panel.example.com', $records['MX @']['value']);
        $this->assertSame('v=spf1 ip4:203.0.113.5 mx ~all', $records['TXT @']['value']);
        $this->assertSame('v=DKIM1; k=rsa; p=KEY', $records['TXT default._domainkey']['value']);
        // The host lives in another zone, so this domain needs no A record.
        $this->assertFalse($records->has('A mail'));
    }

    public function test_a_mail_host_inside_the_domain_gets_an_a_record(): void
    {
        config(['serverpanel.mail.hostname' => 'mail.shop.test', 'serverpanel.mail.server_ip' => '203.0.113.5']);
        $records = collect((new MailDnsRecords)->records('shop.test', 'default', ''))->keyBy(fn ($r) => $r['type'].' '.$r['name']);

        $this->assertSame('203.0.113.5', $records['A mail']['value']);
        $this->assertSame('', $records['TXT default._domainkey']['value']);
    }

    public function test_ipv6_server_uses_ip6_in_spf(): void
    {
        config(['serverpanel.mail.hostname' => 'panel.example.com', 'serverpanel.mail.server_ip' => '2001:db8::5']);

        $this->assertSame('v=spf1 ip6:2001:db8::5 mx ~all', (new MailDnsRecords)->spf());
    }
}
