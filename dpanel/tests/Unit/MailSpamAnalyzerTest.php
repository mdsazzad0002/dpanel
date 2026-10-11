<?php

namespace Tests\Unit;

use App\Services\Mail\MailMessageAnalyzer;
use App\Services\Mail\MailSpamAnalyzer;
use Tests\TestCase;

class MailSpamAnalyzerTest extends TestCase
{
    private function check(string $raw): array
    {
        return (new MailSpamAnalyzer)->analyze($raw, (new MailMessageAnalyzer)->analyze($raw));
    }

    private function names(array $result): array
    {
        return array_column($result['rules'], 'name');
    }

    public function test_phishing_message_is_spam(): void
    {
        $raw = implode("\r\n", [
            'Authentication-Results: mx.example.test; spf=softfail smtp.mailfrom=x@evil.test; dmarc=fail header.from=evil.test',
            'Return-Path: <x@evil.test>',
            'From: "PayPal Security" <alert@evil.test>',
            'Reply-To: refunds.desk@gmail.com',
            'To: undisclosed-recipients:;',
            'Subject: URGENT!!! VERIFY YOUR ACCOUNT NOW',
            'Date: Sat, 10 Oct 2026 10:00:00 +0000',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            '<p>Dear customer, unusual activity. Verify your account immediately:</p>=',
            '<a href=3D"http://198.51.100.7/login">https://www.paypal.com/signin</a>',
            '<a href=3D"https://bit.ly/x">click here</a><form action=3D"http://x"></form>',
            '--b1',
            'Content-Type: application/octet-stream; name="invoice.pdf.exe"',
            'Content-Disposition: attachment; filename="invoice.pdf.exe"',
            'Content-Transfer-Encoding: base64',
            '',
            'TVqQAAMAAAAEAAAA',
            '--b1--',
            '',
        ]);

        $result = $this->check($raw);

        $this->assertSame('spam', $result['verdict']);
        $this->assertGreaterThanOrEqual(MailSpamAnalyzer::SPAM_SCORE, $result['score']);
        foreach (['DMARC_FAIL', 'SPF_SOFTFAIL', 'DKIM_MISSING', 'BRAND_IMPERSONATION', 'REPLYTO_FREEMAIL', 'NO_RECIPIENT', 'SUBJECT_ALL_CAPS',
            'SUBJECT_PUNCTUATION', 'SPAM_PHRASES', 'LINK_MISMATCH', 'LINK_IP_ADDRESS', 'LINK_SHORTENER', 'HTML_FORM', 'HTML_ONLY',
            'DOUBLE_EXTENSION', 'DANGEROUS_ATTACHMENT', 'NO_MESSAGE_ID'] as $rule) {
            $this->assertContains($rule, $this->names($result), $rule);
        }
        $this->assertSame([['name' => 'invoice.pdf.exe', 'risk' => 'high']], $result['attachments']);
        // Rules are listed worst first.
        $this->assertSame('DMARC_FAIL', $result['rules'][0]['name']);
    }

    public function test_ordinary_signed_message_is_clean(): void
    {
        $raw = implode("\r\n", [
            'Authentication-Results: mx.example.test; dkim=pass header.d=shop.test header.s=k1; spf=pass smtp.mailfrom=news@shop.test; dmarc=pass header.from=shop.test',
            'Return-Path: <news@shop.test>',
            'From: Shop <news@shop.test>',
            'To: bob@example.test',
            'Subject: Your order has shipped',
            'Date: Sat, 10 Oct 2026 10:00:00 +0000',
            'Message-ID: <1@shop.test>',
            'Content-Type: multipart/alternative; boundary=alt',
            '',
            '--alt',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Hi Bob, your order is on its way. Track it at https://shop.test/track/1',
            '--alt',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>Hi Bob, your order is on its way.</p><a href="https://shop.test/track/1">Track it</a>',
            '--alt--',
        ]);

        $result = $this->check($raw);

        $this->assertSame('clean', $result['verdict']);
        $this->assertSame([], $result['rules']);
        $this->assertSame(['count' => 1, 'domains' => ['shop.test']], $result['links']);
    }

    public function test_phrases_match_whole_words_only(): void
    {
        $raw = "From: a@b.test\r\nTo: c@d.test\r\nSubject: Cryptography reading list\r\nMessage-ID: <1@b.test>\r\nDate: Sat, 10 Oct 2026 10:00:00 +0000\r\n\r\nNotes on cryptographic hashing.";

        $this->assertNotContains('SPAM_PHRASES', $this->names($this->check($raw)));
    }

    public function test_mime_parts_are_decoded(): void
    {
        $parts = (new MailSpamAnalyzer)->parts(implode("\n", [
            'Content-Type: multipart/mixed; boundary=x',
            '',
            '--x',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('Café'),
            '--x',
            "Content-Disposition: attachment; filename*=utf-8''r%C3%A9sum%C3%A9.docm",
            '',
            'data',
            '--x--',
        ]));

        $this->assertSame([
            ['type' => 'text/plain', 'filename' => '', 'body' => 'Café'],
            ['type' => 'text/plain', 'filename' => 'résumé.docm', 'body' => ''],
        ], $parts);
    }
}
