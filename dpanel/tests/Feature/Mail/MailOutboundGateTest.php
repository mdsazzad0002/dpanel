<?php

namespace Tests\Feature\Mail;

use App\Services\Dns\PublicDnsLookup;
use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailOutboundGate;
use App\Services\ScriptExecutionGateway;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class MailOutboundGateTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.10';

    /** @var array<int, array<int, string>> arguments of every policy-script call */
    private array $calls = [];

    private string $postfixStatus = "MAIL_OUTBOUND_IPV6=allow\nMAIL_OUTBOUND=active\nMAIL_OUTBOUND_CHANGED=0\n";

    private bool $applySucceeds = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('mail.outbound_gate');
        // Holding mail is opt-in; most cases test it switched on.
        app(MailSettings::class)->write(['outbound_gate' => true]);

        $records = Mockery::mock(MailDnsRecords::class);
        $records->shouldReceive('serverIp')->andReturn(self::IP);
        $this->app->instance(MailDnsRecords::class, $records);

        $gateway = Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(function (string $script, array $args) {
            $this->calls[] = $args;
            if ($args === ['--status']) {
                return ['success' => true, 'output' => $this->postfixStatus];
            }

            return $this->applySucceeds
                ? ['success' => true, 'output' => "MAIL_OUTBOUND_CHANGED=1\n"]
                : ['success' => false, 'output' => 'postfix check failed'];
        });
        $this->app->instance(ScriptExecutionGateway::class, $gateway);
    }

    private function dns(string $ptr, array $addresses, bool $failed = false): void
    {
        $dns = Mockery::mock(PublicDnsLookup::class);
        $dns->shouldReceive('ptr')->andReturn($ptr === '' ? [] : [$ptr.'.']);
        $dns->shouldReceive('records')->andReturn($addresses);
        $dns->shouldReceive('failed')->andReturn($failed);
        $this->app->instance(PublicDnsLookup::class, $dns);
    }

    private function check(): array
    {
        return $this->app->make(MailOutboundGate::class)->check();
    }

    public function test_matching_ptr_keeps_outbound_on_and_ipv6_off_without_a_proven_ipv6(): void
    {
        $this->dns('mail.example.test', [self::IP]);

        $state = $this->check();

        $this->assertSame('active', $state['outbound']);
        $this->assertSame('deny', $state['ipv6']);
        $this->assertContains(['--ipv6=deny', '--outbound=active'], $this->calls);
    }

    public function test_missing_ptr_pauses_only_after_two_failed_checks(): void
    {
        $this->dns('', []);

        $this->assertSame('active', $this->check()['outbound']);
        $state = $this->check();

        $this->assertSame('paused', $state['outbound']);
        $this->assertStringContainsString('has no PTR', $state['reason']);
        $this->assertContains(['--ipv6=deny', '--outbound=paused'], $this->calls);
    }

    public function test_ptr_pointing_elsewhere_counts_as_failure(): void
    {
        $this->dns('mail.example.test', ['198.51.100.7']);
        $this->check();

        $state = $this->check();

        $this->assertSame('paused', $state['outbound']);
        $this->assertStringContainsString('pointing back', $state['reason']);
    }

    public function test_unanswered_dns_never_changes_the_state(): void
    {
        $this->dns('', [], failed: true);

        $this->check();
        $state = $this->check();

        $this->assertSame('active', $state['outbound']);
        $this->assertSame(0, $state['failures']);
    }

    public function test_fixed_ptr_reopens_outbound(): void
    {
        $this->dns('', []);
        $this->check();
        $this->check();

        $this->dns('mail.example.test', [self::IP]);
        $state = $this->check();

        $this->assertSame('active', $state['outbound']);
        $this->assertSame(0, $state['failures']);
    }

    public function test_a_cleared_cache_does_not_lift_a_pause_postfix_still_has(): void
    {
        $this->postfixStatus = "MAIL_OUTBOUND_IPV6=deny\nMAIL_OUTBOUND=paused\nMAIL_OUTBOUND_CHANGED=0\n";
        $this->dns('', []);

        $state = $this->check();

        $this->assertSame('paused', $state['outbound']);
        $this->assertContains(['--status'], $this->calls);
    }

    public function test_a_failed_apply_reports_the_previous_state(): void
    {
        $this->dns('mail.example.test', [self::IP]);
        $this->applySucceeds = false;

        $state = $this->check();

        $this->assertSame('postfix check failed', $state['error']);
        $this->assertSame('allow', $state['ipv6']);
        $this->assertSame('active', $state['outbound']);
    }

    public function test_holding_is_off_by_default(): void
    {
        \Illuminate\Support\Facades\DB::table('mail_settings')->delete();
        $this->dns('', []);

        $this->check();
        $state = $this->check();

        $this->assertSame('active', $state['outbound']);
        $this->assertSame('deny', $state['ipv6']);
    }

    public function test_turning_holding_off_releases_paused_mail(): void
    {
        $this->dns('', []);
        $this->check();
        $this->assertSame('paused', $this->check()['outbound']);

        $state = $this->app->make(MailOutboundGate::class)->setEnabled(false);

        $this->assertSame('active', $state['outbound']);
        $this->assertFalse(app(MailSettings::class)->read()['outbound_gate']);
    }
}
