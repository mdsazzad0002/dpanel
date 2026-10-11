<?php

namespace Tests\Unit;

use App\Services\Mail\MailMessageAnalyzer;
use PHPUnit\Framework\TestCase;

class MailMessageAnalyzerTest extends TestCase
{
    private const GMAIL = <<<'EOT'
Return-Path: <alice@gmail.com>
Delivered-To: bob@example.test
Received: from mail-sor-f41.google.com (mail-sor-f41.google.com [209.85.220.41])
	by mail.example.test (Postfix) with ESMTPS id 4F1C2A0123
	for <bob@example.test>; Sat, 10 Oct 2026 10:00:07 +0000 (UTC)
Authentication-Results: mail.example.test;
	dkim=pass (2048-bit key; unprotected) header.d=gmail.com header.i=@gmail.com header.s=20230601 header.b=AbCdEf;
	spf=pass smtp.mailfrom=alice@gmail.com;
	dmarc=pass (p=NONE) header.from=gmail.com
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=gmail.com; s=20230601;
	h=to:subject:message-id:date:from:mime-version; bh=abc=; b=xyz=
X-Google-Smtp-Source: AGHT+IF1
Received: by mail-sor-f41.google.com with SMTP id 5b1f17b1804b1
	for <bob@example.test>; Sat, 10 Oct 2026 03:00:05 -0700 (PDT)
From: Alice =?UTF-8?B?w4FsdmFyZXo=?= <alice@gmail.com>
Date: Sat, 10 Oct 2026 12:00:04 +0200
Message-ID: <CAF=abc@mail.gmail.com>
Subject: =?UTF-8?Q?Caf=C3=A9_plans?=
To: bob@example.test
EOT;

    public function test_gmail_message_passes_every_check(): void
    {
        $a = (new MailMessageAnalyzer)->analyze(self::GMAIL);

        $this->assertSame('Gmail / Google Workspace', $a['provider']);
        $this->assertSame('pass', $a['auth']['spf']['result']);
        $this->assertSame('pass', $a['auth']['dmarc']['result']);
        $this->assertSame([['result' => 'pass', 'domain' => 'gmail.com', 'selector' => '20230601']], $a['auth']['dkim']);
        $this->assertSame(['spf' => true, 'dkim' => true], $a['alignment']);
        $this->assertSame('Café plans', $a['summary']['subject']);
        $this->assertSame('Alice Álvarez <alice@gmail.com>', $a['summary']['from']);
        $this->assertSame('alice@gmail.com', $a['summary']['return_path']);
        $this->assertSame([], $a['warnings']);
    }

    public function test_relay_path_is_oldest_first_with_delays_and_tls(): void
    {
        $hops = (new MailMessageAnalyzer)->analyze(self::GMAIL)['hops'];

        $this->assertCount(2, $hops);
        $this->assertSame('mail-sor-f41.google.com', $hops[0]['by']);
        $this->assertNull($hops[0]['delay']);
        $this->assertSame('mail.example.test (Postfix)', $hops[1]['by']);
        $this->assertSame('ESMTPS', $hops[1]['with']);
        $this->assertTrue($hops[1]['tls']);
        $this->assertSame(2, $hops[1]['delay']);
        // The first hop is a hand-off inside Google; only server-to-server hops count.
        $this->assertFalse($hops[0]['tls']);
        $this->assertTrue((new MailMessageAnalyzer)->analyze(self::GMAIL)['tls']);
    }

    public function test_spoofed_message_reports_failures(): void
    {
        $raw = <<<'EOT'
Authentication-Results: mx.example.test; spf=fail smtp.mailfrom=bounce@evil.test; dmarc=fail header.from=bank.test
Received: from evil.test (evil.test [198.51.100.9]) by mx.example.test with SMTP; Sat, 10 Oct 2026 10:00:00 +0000
Return-Path: <bounce@evil.test>
From: Bank <support@bank.test>
X-Spam-Status: Yes, score=9.1 required=5.0
Subject: Verify your account
EOT;
        $a = (new MailMessageAnalyzer)->analyze($raw);

        $this->assertSame('fail', $a['auth']['spf']['result']);
        $this->assertSame(['spf' => false, 'dkim' => false], $a['alignment']);
        $this->assertSame(['flag' => 'yes', 'score' => '9.1', 'detail' => 'Yes, score=9.1 required=5.0'], $a['spam']);
        $this->assertFalse($a['tls']);
        $warnings = implode("\n", $a['warnings']);
        foreach (['DMARC failed', 'SPF fail', 'No DKIM signature', 'Neither SPF nor DKIM aligns', 'Marked as spam (score 9.1)', 'did not use TLS', 'No Message-ID'] as $expected) {
            $this->assertStringContainsString($expected, $warnings);
        }
    }

    public function test_unverified_signature_and_bulk_headers(): void
    {
        $raw = <<<'EOT'
From: news@shop.example.co.uk
Return-Path: <bounces@mail.shop.example.co.uk>
DKIM-Signature: v=1; d=shop.example.co.uk; s=k1; b=abc
Message-ID: <1@shop.example.co.uk>
List-Id: <news.shop.example.co.uk>
List-Unsubscribe: <https://shop.example.co.uk/u>
X-SG-EID: abc
X-Dpanel-Sandbox: not sent to x@gmail.com
EOT;
        $a = (new MailMessageAnalyzer)->analyze($raw);

        $this->assertSame('SendGrid', $a['provider']);
        $this->assertSame([['result' => 'unverified', 'domain' => 'shop.example.co.uk', 'selector' => 'k1']], $a['auth']['dkim']);
        // Relaxed alignment: mail.shop.example.co.uk and shop.example.co.uk share example.co.uk.
        $this->assertTrue($a['alignment']['spf']);
        $this->assertFalse($a['alignment']['dkim']);
        $this->assertSame('not sent to x@gmail.com', $a['sandbox']);
        $this->assertNull($a['tls']);
        $this->assertStringContainsString('one-click', implode("\n", $a['warnings']));
    }

    public function test_headers_are_unfolded_and_body_is_ignored(): void
    {
        $headers = (new MailMessageAnalyzer)->parseHeaders("Subject: one\r\n two\r\nX-A: b\r\n\r\nBody: not a header\r\n");

        $this->assertSame([
            ['name' => 'Subject', 'key' => 'subject', 'value' => 'one two'],
            ['name' => 'X-A', 'key' => 'x-a', 'value' => 'b'],
        ], $headers);
    }
}
