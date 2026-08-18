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
}
