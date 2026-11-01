<?php

namespace Tests\Unit;

use App\Services\Mail\MailDeliveryDiagnosticsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailDeliveryDiagnosticsServiceTest extends TestCase
{
    /** @return array<string, array{string, string, string, bool}> */
    public static function failureReasons(): array
    {
        return [
            'unknown mailbox' => ['550 5.1.1 User unknown', 'bounced', 'mailbox_missing', false],
            'quota' => ['452 4.2.2 Mailbox full', 'deferred', 'quota', true],
            'dns' => ['Host not found, try again', 'deferred', 'dns', true],
            'authentication policy' => ['550 5.7.26 Unauthenticated email: DKIM fail', 'bounced', 'authentication_policy', false],
            'rate limit' => ['421 4.7.0 Too many messages', 'deferred', 'rate_limit', true],
            'network' => ['connect to mx.example.test: Connection timed out', 'deferred', 'network', true],
            'missing ptr' => ['550-5.7.25 [2a02:c207::1] The IP address sending this message does not have a PTR record setup. As a policy, Gmail does not accept messages from IPs with missing PTR records.', 'bounced', 'ptr', false],
            'longer status code is not 5.7.1' => ['550 5.7.10 something new', 'bounced', 'other', false],
            'residential ip' => ["550-5.7.1 [203.0.113.5] The IP you're using to send mail is not authorized to 550-5.7.1 send email directly to our servers. Please use the SMTP relay at your service provider instead.", 'bounced', 'reputation', false],
        ];
    }

    #[DataProvider('failureReasons')]
    public function test_it_classifies_common_delivery_failures(
        string $reason,
        string $status,
        string $category,
        bool $temporary
    ): void {
        $diagnosis = (new MailDeliveryDiagnosticsService())->classifyFailure($reason, $status);

        $this->assertSame($category, $diagnosis['category']);
        $this->assertSame($temporary, $diagnosis['temporary']);
        $this->assertNotSame('', $diagnosis['explanation']);
        $this->assertNotSame('', $diagnosis['suggestion']);
    }

    public function test_unknown_deferred_response_gets_a_safe_temporary_fallback(): void
    {
        $diagnosis = (new MailDeliveryDiagnosticsService())->classifyFailure('451 unusual remote response', 'deferred');

        $this->assertSame('other', $diagnosis['category']);
        $this->assertTrue($diagnosis['temporary']);
    }

    public function test_outside_clients_refused_by_postfix_are_blocked_not_failures(): void
    {
        $lines = [
            // Outsiders trying to relay or probe: the server defending itself.
            '2026-10-16T11:00:08+06:00 mail postfix/smtpd[85731]: NOQUEUE: reject: RCPT from unknown[185.169.4.229]: 454 4.7.1 <spameri@tiscali.it>: Relay access denied; from=<spameri@tiscali.it> to=<spameri@tiscali.it> proto=ESMTP helo=<WIN-CLJ1B0GQ6JP>',
            '2026-10-16T09:11:38+06:00 mail postfix/smtpd[80060]: NOQUEUE: reject: RCPT from smtp.www-openbugbounty.org[94.72.105.127]: 550 5.1.1 <info@dengrweb.com>: Recipient address rejected: User unknown in virtual mailbox table; from=<security@www-openbugbounty.org> to=<info@dengrweb.com> proto=ESMTP',
            // Our own sender refused: a real problem.
            '2026-10-16T12:00:00+06:00 mail postfix/submission/smtpd[1]: NOQUEUE: reject: RCPT from localhost[127.0.0.1]: 554 5.7.1 <x@y.test>: Recipient address rejected: Access denied; from=<dev@dengrweb.com> to=<x@y.test> proto=ESMTP',
            '2026-10-16T12:00:01+06:00 mail postfix/postfix/smtp[2]: ABC123: to=<a@b.test>, relay=mx.b.test[1.2.3.4]:25, status=sent (250 ok)',
        ];
        $method = new \ReflectionMethod(MailDeliveryDiagnosticsService::class, 'parseLogLines');
        $parsed = $method->invoke(new MailDeliveryDiagnosticsService(), $lines);

        $this->assertSame(2, $parsed['stats']['blocked']);
        $this->assertSame(1, $parsed['stats']['rejected']);
        $this->assertCount(1, $parsed['failures']);

        $score = new \ReflectionMethod(MailDeliveryDiagnosticsService::class, 'healthScore');
        $outsideOnly = ['sent' => 1, 'bounced' => 0, 'deferred' => 0, 'rejected' => 0, 'spam' => 0, 'blocked' => 50];
        $this->assertSame(100, $score->invoke(new MailDeliveryDiagnosticsService(), $outsideOnly, ['available' => true, 'count' => 0], '/var/log/mail.log'));
    }
}
