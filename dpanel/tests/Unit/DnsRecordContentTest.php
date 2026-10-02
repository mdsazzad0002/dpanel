<?php

namespace Tests\Unit;

use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsZoneRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class DnsRecordContentTest extends TestCase
{
    private function normalize(string $type, string $content, ?int $priority = null): array
    {
        return app(DnsRecordContent::class)->normalize($type, $content, $priority, 'example.com');
    }

    public function test_addresses_must_match_the_record_type(): void
    {
        $this->assertSame('203.0.113.5', $this->normalize('A', ' 203.0.113.5 ')['content']);
        $this->assertSame('2001:db8::1', $this->normalize('AAAA', '2001:DB8::1')['content']);

        $this->expectException(InvalidArgumentException::class);
        $this->normalize('A', '2001:db8::1');
    }

    public function test_host_names_lose_the_trailing_dot_and_at_means_the_apex(): void
    {
        $this->assertSame('target.example.net', $this->normalize('CNAME', 'Target.Example.net.')['content']);
        $this->assertSame('example.com', $this->normalize('CNAME', '@')['content']);

        $this->expectException(InvalidArgumentException::class);
        $this->normalize('CNAME', '203.0.113.5');
    }

    public function test_mx_accepts_a_pasted_priority(): void
    {
        $this->assertSame(['content' => 'mail.example.com', 'priority' => 20], $this->normalize('MX', '20 mail.example.com.'));
        $this->assertSame(['content' => 'mail.example.com', 'priority' => 5], $this->normalize('MX', 'mail.example.com', 5));
    }

    public function test_txt_is_quoted_and_split_into_255_byte_strings(): void
    {
        $this->assertSame('"v=spf1 mx -all"', $this->normalize('TXT', 'v=spf1 mx -all')['content']);
        $this->assertSame('"already quoted"', $this->normalize('TXT', '"already quoted"')['content']);

        $long = $this->normalize('TXT', str_repeat('a', 300))['content'];
        $this->assertSame('"'.str_repeat('a', 255).'" "'.str_repeat('a', 45).'"', $long);
        $this->assertSame(str_repeat('a', 300), app(DnsRecordContent::class)->unquoteTxt($long));
    }

    public function test_srv_keeps_priority_in_its_own_column(): void
    {
        $this->assertSame(['content' => '5 5060 sip.example.com', 'priority' => 10], $this->normalize('SRV', '10 5 5060 sip.example.com.'));

        $this->expectException(InvalidArgumentException::class);
        $this->normalize('SRV', 'sip.example.com');
    }

    public function test_caa_value_is_quoted(): void
    {
        $this->assertSame('0 issue "letsencrypt.org"', $this->normalize('CAA', '0 issue letsencrypt.org')['content']);
        $this->assertSame('128 iodef "mailto:sec@example.com"', $this->normalize('CAA', '128 IODEF "mailto:sec@example.com"')['content']);

        $this->expectException(InvalidArgumentException::class);
        $this->normalize('CAA', '0 bogus "x"');
    }

    public function test_record_names_allow_underscores_and_a_leading_wildcard(): void
    {
        $content = app(DnsRecordContent::class);
        $content->assertValidName('_dmarc.example.com');
        $content->assertValidName('*.example.com');
        $this->addToAssertionCount(2);

        $this->expectException(InvalidArgumentException::class);
        $content->assertValidName('bad label.example.com');
    }

    public function test_serial_follows_the_date_and_never_goes_back(): void
    {
        $today = new \DateTimeImmutable('2026-10-02');
        $this->assertSame(2026100201, DnsZoneRules::nextSerial(0, $today));
        $this->assertSame(2026100201, DnsZoneRules::nextSerial(2026093005, $today));
        $this->assertSame(2026100208, DnsZoneRules::nextSerial(2026100207, $today));
        $this->assertSame(2026100300, DnsZoneRules::nextSerial(2026100299, $today));
        $this->assertSame(
            'ns1.example.com hostmaster.example.com 2026100202 3600 600 1209600 3600',
            DnsZoneRules::withSerial('ns1.example.com hostmaster.example.com 2026100201 3600 600 1209600 3600', 2026100202),
        );
    }

    public function test_cname_cannot_share_a_name(): void
    {
        Schema::create('records', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('domain_id');
            $table->string('name');
            $table->string('type');
            $table->text('content');
        });
        DB::table('records')->insert(['domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.5']);
        $rules = app(DnsZoneRules::class);

        $rules->assertNoCnameConflict(DB::connection(), 1, 'example.com', 'blog.example.com', 'CNAME');
        $rules->assertNoCnameConflict(DB::connection(), 1, 'example.com', 'www.example.com', 'A');
        $this->addToAssertionCount(2);

        foreach ([['www.example.com', 'CNAME'], ['example.com', 'CNAME']] as [$name, $type]) {
            try {
                $rules->assertNoCnameConflict(DB::connection(), 1, 'example.com', $name, $type);
                $this->fail("{$type} at {$name} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
