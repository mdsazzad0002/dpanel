<?php

namespace Tests\Feature\Mail;

use App\Http\Controllers\MailHealthController;
use App\Models\Mailbox;
use App\Services\Mail\MailboxDeliveryHealth;
use App\Services\Mail\MailOutboundGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MailHealthChecksEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function gateReturning(array $state): MailOutboundGate
    {
        $gate = Mockery::mock(MailOutboundGate::class);
        $gate->shouldReceive('check')->andReturn($state + [
            'ipv6' => 'deny', 'outbound' => 'active', 'failures' => 0, 'reason' => 'r', 'error' => null,
            'facts' => ['relayhost' => '', 'ipv4' => null, 'ipv6' => null],
        ]);

        return $gate;
    }

    private function row(bool $ok, string $ip = '203.0.113.10'): array
    {
        return ['ip' => $ip, 'ptr' => $ok ? 'mail.example.test' : '', 'ok' => $ok, 'lookup_failed' => false, 'message' => $ok ? '' : "{$ip} has no PTR (reverse DNS) record."];
    }

    public function test_paused_outbound_is_an_error(): void
    {
        $data = (new MailHealthController)->outboundCheck($this->gateReturning([
            'outbound' => 'paused',
            'facts' => ['relayhost' => '', 'ipv4' => $this->row(false), 'ipv6' => null],
        ]))->getData(true);

        $this->assertSame('error', $data['level']);
        $this->assertSame('Outbound mail is paused', $data['title']);
    }

    public function test_first_ptr_failure_is_a_warning(): void
    {
        $data = (new MailHealthController)->outboundCheck($this->gateReturning([
            'facts' => ['relayhost' => '', 'ipv4' => $this->row(false), 'ipv6' => null],
        ]))->getData(true);

        $this->assertSame('warning', $data['level']);
    }

    public function test_missing_ipv6_ptr_is_a_warning_with_a_note(): void
    {
        $data = (new MailHealthController)->outboundCheck($this->gateReturning([
            'facts' => ['relayhost' => '', 'ipv4' => $this->row(true), 'ipv6' => $this->row(false, '2001:db8::1')],
        ]))->getData(true);

        $this->assertSame('warning', $data['level']);
        $this->assertStringContainsString('IPv4 only', $data['notes'][0]);
    }

    public function test_correct_ptr_is_success(): void
    {
        $data = (new MailHealthController)->outboundCheck($this->gateReturning([
            'facts' => ['relayhost' => '', 'ipv4' => $this->row(true), 'ipv6' => null],
        ]))->getData(true);

        $this->assertSame('success', $data['level']);
    }

    public function test_enable_endpoint_returns_every_check(): void
    {
        $mailbox = Mailbox::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => 'example.test', 'mailbox' => 'a',
            'email' => 'a@example.test', 'password' => 'x', 'status' => 'disabled',
        ]);
        $health = Mockery::mock(MailboxDeliveryHealth::class);
        $health->shouldReceive('enable')->andReturn([
            'enabled' => false,
            'checks' => [['name' => 'Dovecot can find the mailbox', 'ok' => false, 'message' => 'nope']],
        ]);

        $data = app(\App\Http\Controllers\EmailController::class)->enable($health, 'token', $mailbox->id)->getData(true);

        $this->assertFalse($data['enabled']);
        $this->assertSame('a@example.test stays off', $data['title']);
        $this->assertSame('nope', $data['checks'][0]['message']);
    }
}
