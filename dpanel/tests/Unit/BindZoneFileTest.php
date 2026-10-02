<?php

namespace Tests\Unit;

use App\Services\Dns\BindZoneFile;
use Tests\TestCase;

class BindZoneFileTest extends TestCase
{
    public function test_it_reads_a_cloudflare_export(): void
    {
        $file = <<<'ZONE'
;;
;; Domain:     example.com.
;; Exported:   2026-09-30 10:00:00
;;
;; SOA Record
example.com	3600	IN	SOA	ada.ns.cloudflare.com. dns.cloudflare.com. 2051234567 10000 2400 604800 3600

;; NS Records
example.com.	86400	IN	NS	ada.ns.cloudflare.com.

;; A Records
example.com.	1	IN	A	203.0.113.10 ; cf_tags=cf-proxied:true
www.example.com.	1	IN	CNAME	example.com.

;; MX Records
example.com.	300	IN	MX	10 mail.example.com.

;; SRV Records
_sip._tcp.example.com.	300	IN	SRV	10 5 5060 sip.example.com.

;; TXT Records
example.com.	300	IN	TXT	"v=spf1 mx -all"
_dmarc.example.com.	300	IN	TXT	"v=DMARC1; p=none"
other.org.	300	IN	A	198.51.100.1
ZONE;

        $result = app(BindZoneFile::class)->parse($file, 'example.com');

        $this->assertSame([
            ['name' => 'example.com', 'type' => 'A', 'ttl' => 300, 'content' => '203.0.113.10', 'priority' => null],
            ['name' => 'www.example.com', 'type' => 'CNAME', 'ttl' => 300, 'content' => 'example.com', 'priority' => null],
            ['name' => 'example.com', 'type' => 'MX', 'ttl' => 300, 'content' => 'mail.example.com', 'priority' => 10],
            ['name' => '_sip._tcp.example.com', 'type' => 'SRV', 'ttl' => 300, 'content' => '5 5060 sip.example.com', 'priority' => 10],
            ['name' => 'example.com', 'type' => 'TXT', 'ttl' => 300, 'content' => '"v=spf1 mx -all"', 'priority' => null],
            ['name' => '_dmarc.example.com', 'type' => 'TXT', 'ttl' => 300, 'content' => '"v=DMARC1; p=none"', 'priority' => null],
        ], $result['records']);
        $this->assertCount(2, $result['skipped']);
        $this->assertStringContainsString('apex NS', $result['skipped'][0]);
        $this->assertStringContainsString('outside example.com', $result['skipped'][1]);
    }

    public function test_it_follows_origin_ttl_relative_names_and_parentheses(): void
    {
        $file = <<<'ZONE'
$ORIGIN example.com.
$TTL 1h
@	IN	SOA	ns1 hostmaster (
		2026100201 ; serial
		3600 600 1209600 3600 )
www		CNAME	@
blog	2d	IN	CNAME	www
		IN	TXT	hello world
ZONE;

        $records = app(BindZoneFile::class)->parse($file, 'example.com')['records'];

        $this->assertSame([
            ['name' => 'www.example.com', 'type' => 'CNAME', 'ttl' => 3600, 'content' => 'example.com', 'priority' => null],
            ['name' => 'blog.example.com', 'type' => 'CNAME', 'ttl' => 86400, 'content' => 'www.example.com', 'priority' => null],
            ['name' => 'blog.example.com', 'type' => 'TXT', 'ttl' => 3600, 'content' => '"hello" "world"', 'priority' => null],
        ], $records);
    }

    public function test_export_writes_absolute_names_that_parse_back(): void
    {
        $zoneFile = app(BindZoneFile::class);
        $text = $zoneFile->export('example.com', [
            (object) ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 2026100201 3600 600 1209600 3600', 'ttl' => 3600, 'prio' => 0],
            (object) ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 300, 'prio' => 10],
            (object) ['name' => '_sip._tcp.example.com', 'type' => 'SRV', 'content' => '5 5060 sip.example.com', 'ttl' => 300, 'prio' => 20],
        ]);

        $this->assertStringContainsString("example.com.\t3600\tIN\tSOA\tns1.example.com. hostmaster.example.com. 2026100201", $text);
        $this->assertStringContainsString("example.com.\t300\tIN\tMX\t10 mail.example.com.", $text);
        $this->assertStringContainsString("_sip._tcp.example.com.\t300\tIN\tSRV\t20 5 5060 sip.example.com.", $text);

        $records = collect($zoneFile->parse($text, 'example.com')['records'])->keyBy('type');
        $this->assertSame(['mail.example.com', 10], [$records['MX']['content'], $records['MX']['priority']]);
        $this->assertSame(['5 5060 sip.example.com', 20], [$records['SRV']['content'], $records['SRV']['priority']]);
    }
}
