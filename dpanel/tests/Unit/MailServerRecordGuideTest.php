<?php

namespace Tests\Unit;

use App\Services\Dns\PublicDnsLookup;
use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailDnsVerifier;
use App\Services\Mail\MailServerRecordGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDns;
use Tests\TestCase;

class MailServerRecordGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dpanel.mail.hostname' => 'mail.example.net', 'dpanel.mail.server_ip' => '203.0.113.5']);
    }

    public function test_the_table_states_every_record_and_checks_the_server_ones(): void
    {
        $table = $this->table([
            'A mail.example.net' => ['203.0.113.5'],
            'PTR 203.0.113.5' => ['mail.example.net'],
        ]);
        $rows = collect($table['rows'])->keyBy('key');

        $this->assertSame('mail.example.net', $table['host']);
        $this->assertSame('203.0.113.5', $table['ipv4']);
        $this->assertSame('', $table['ipv6']);

        $this->assertSame('203.0.113.5', $rows['host']['value']);
        $this->assertSame('pass', $rows['host']['status']);
        $this->assertSame('mail.example.net', $rows['ptr']['value']);
        $this->assertSame('pass', $rows['ptr']['status']);

        // Per-domain rows are reference values, not live checks.
        $this->assertSame('mail.example.net', $rows['mx']['value']);
        $this->assertSame(10, $rows['mx']['priority']);
        $this->assertSame('v=spf1 ip4:203.0.113.5 mx ~all', $rows['spf']['value']);
        $this->assertFalse($rows['dmarc']['checked']);
        $this->assertSame('unknown', $rows['dkim']['status']);
    }

    public function test_a_server_without_ipv6_says_so_instead_of_asking_for_records(): void
    {
        $rows = collect($this->table([])['rows'])->keyBy('key');

        $this->assertSame('', $rows['host6']['value']);
        $this->assertFalse($rows['host6']['required']);
        $this->assertStringContainsString('no public IPv6', $rows['host6']['purpose']);
        $this->assertFalse($rows->has('ptr6'));
    }

    public function test_a_dual_stack_server_gets_aaaa_and_ipv6_ptr_rows(): void
    {
        config(['dpanel.mail.server_ipv6' => '2001:db8::5']);
        $rows = collect($this->table([
            'A mail.example.net' => ['203.0.113.5'],
            'PTR 203.0.113.5' => ['mail.example.net'],
            'AAAA mail.example.net' => ['2001:db8::5'],
        ])['rows'])->keyBy('key');

        $this->assertSame('2001:db8::5', $rows['host6']['value']);
        $this->assertSame('pass', $rows['host6']['status']);
        $this->assertSame('mail.example.net', $rows['ptr6']['value']);
        // No IPv6 PTR published yet, which only holds IPv6 sending back.
        $this->assertSame('warn', $rows['ptr6']['status']);
        $this->assertStringContainsString('ip6:2001:db8::5', $rows['spf']['value']);
    }

    public function test_an_unknown_server_address_skips_the_live_lookups(): void
    {
        config(['dpanel.mail.server_ip' => '']);
        $rows = collect($this->table([])['rows'])->keyBy('key');

        $this->assertFalse($rows['host']['checked']);
        $this->assertSame('unknown', $rows['ptr']['status']);
    }

    /** @param  array<string, array<int, string>>  $dns */
    private function table(array $dns): array
    {
        $fake = new FakeDns($dns);
        $this->app->instance(PublicDnsLookup::class, $fake);
        $records = new MailDnsRecords;

        return (new MailServerRecordGuide($records, new MailDnsVerifier($records, $fake)))->table();
    }
}
