<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Services\Mail\MailboxDeliveryHealth;
use App\Services\ScriptExecutionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MailboxDeliveryHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> addresses Dovecot cannot find */
    private array $dovecotMissing = [];

    /** Dovecot finds these again once the repair script has run. */
    private array $fixedByRepair = [];

    private ?string $dovecotError = null;

    private int $repairs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(function (string $script, array $args) {
            if (str_ends_with($script, 'mail-repair-dovecot.sh')) {
                $this->repairs++;
                $this->dovecotMissing = array_values(array_diff($this->dovecotMissing, $this->fixedByRepair));

                return ['success' => true, 'output' => "MAIL_REPAIR=ok\n"];
            }
            if ($this->dovecotError !== null) {
                return ['success' => false, 'output' => "ERROR {$this->dovecotError}\n"];
            }

            return ['success' => true, 'output' => implode("\n", array_map(
                fn ($email) => in_array($email, $this->dovecotMissing, true)
                    ? "MISSING {$email} userdb lookup: user {$email} doesn't exist"
                    : "OK {$email}",
                $args,
            ))."\n"];
        });
        $this->app->instance(ScriptExecutionGateway::class, $gateway);
    }

    private function mailbox(string $email, string $status = 'active'): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'mailbox' => $local,
            'email' => $email,
            'password' => 'x',
            'status' => $status,
            'mail_home' => "/home/test/mail/{$domain}/{$local}/Maildir",
        ]);
    }

    private function health(): MailboxDeliveryHealth
    {
        return $this->app->make(MailboxDeliveryHealth::class);
    }

    public function test_mailboxes_dovecot_finds_stay_active(): void
    {
        $a = $this->mailbox('a@example.test');

        $report = $this->health()->checkAll();

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['unhealthy']);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->health_checked_at);
        $this->assertSame(0, $this->repairs);
    }

    public function test_a_mailbox_still_missing_after_repair_is_switched_off(): void
    {
        $ok = $this->mailbox('ok@example.test');
        $broken = $this->mailbox('security@example.test');
        $this->dovecotMissing = ['security@example.test'];

        $report = $this->health()->checkAll();

        $this->assertSame(1, $this->repairs);
        $this->assertSame(['security@example.test'], $report['unhealthy']);
        $this->assertSame('unhealthy', $broken->fresh()->status);
        $this->assertStringContainsString("doesn't exist", (string) $broken->fresh()->health_error);
        $this->assertSame('active', $ok->fresh()->status);
    }

    public function test_a_mailbox_the_repair_fixes_stays_active(): void
    {
        $mailbox = $this->mailbox('security@example.test');
        $this->dovecotMissing = $this->fixedByRepair = ['security@example.test'];

        $report = $this->health()->checkAll();

        $this->assertTrue($report['repaired']);
        $this->assertSame([], $report['unhealthy']);
        $this->assertSame('active', $mailbox->fresh()->status);
    }

    public function test_dovecot_failing_to_answer_changes_nothing(): void
    {
        $mailbox = $this->mailbox('a@example.test');
        $this->dovecotError = 'doveadm user failed (exit 75): auth service down';

        $report = $this->health()->checkAll();

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('auth service down', (string) $report['error']);
        $this->assertSame('active', $mailbox->fresh()->status);
    }

    public function test_dovecot_finding_no_mailbox_at_all_is_reported_not_applied(): void
    {
        $emails = ['a@example.test', 'b@example.test', 'c@example.test'];
        foreach ($emails as $email) {
            $this->mailbox($email);
        }
        $this->dovecotMissing = $emails;

        $report = $this->health()->checkAll();

        $this->assertFalse($report['ok']);
        $this->assertSame(3, Mailbox::query()->where('status', 'active')->count());
    }

    public function test_dry_run_reports_without_repairing_or_switching_off(): void
    {
        $mailbox = $this->mailbox('security@example.test');
        $this->mailbox('ok@example.test');
        $this->dovecotMissing = ['security@example.test'];

        $report = $this->health()->checkAll(dryRun: true);

        $this->assertSame(['security@example.test'], $report['unhealthy']);
        $this->assertSame(0, $this->repairs);
        $this->assertSame('active', $mailbox->fresh()->status);
    }

    public function test_an_unhealthy_mailbox_is_restored_once_dovecot_finds_it(): void
    {
        $mailbox = $this->mailbox('security@example.test', 'unhealthy');

        $report = $this->health()->checkAll();

        $this->assertSame(['security@example.test'], $report['restored']);
        $this->assertSame('active', $mailbox->fresh()->status);
        $this->assertNull($mailbox->fresh()->health_error);
    }

    public function test_recheck_puts_a_still_missing_mailbox_back_to_unhealthy(): void
    {
        $mailbox = $this->mailbox('security@example.test', 'unhealthy');
        $this->dovecotMissing = ['security@example.test'];

        $result = $this->health()->recheck($mailbox);

        $this->assertTrue($result['ok']);
        $this->assertSame('unhealthy', $mailbox->fresh()->status);
    }
}
