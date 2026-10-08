<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Services\Mail\MailboxDeliveryHealth;
use App\Services\ScriptExecutionGateway;
use App\Support\MailPasswordHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MailboxDeliveryHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> addresses Dovecot cannot find */
    private array $dovecotMissing = [];

    private ?string $dovecotError = null;

    /** @var array<int, string> statuses seen by Dovecot lookups, per call */
    private array $statusDuringLookup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(function (string $script, array $args) {
            foreach ($args as $email) {
                $this->statusDuringLookup[] = (string) Mailbox::query()->where('email', $email)->value('status');
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

    private function mailbox(string $email, string $status = 'disabled', array $attributes = []): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate($attributes + [
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'mailbox' => $local,
            'email' => $email,
            'password' => MailPasswordHash::make('secret-pass'),
            'client_password' => 'secret-pass',
            'status' => $status,
            'mail_home' => "/home/test/mail/{$domain}/{$local}/Maildir",
            'mail_uid' => 1001,
            'mail_gid' => 1001,
        ]);
    }

    private function health(): MailboxDeliveryHealth
    {
        return $this->app->make(MailboxDeliveryHealth::class);
    }

    public function test_enable_turns_on_when_every_check_passes(): void
    {
        $mailbox = $this->mailbox('a@example.test');

        $result = $this->health()->enable($mailbox);

        $this->assertTrue($result['enabled']);
        $this->assertCount(3, $result['checks']);
        $this->assertSame('active', $mailbox->fresh()->status);
        $this->assertNull($mailbox->fresh()->health_error);
        // Dovecot only finds active rows, so it must be asked while the row is active.
        $this->assertSame(['active'], $this->statusDuringLookup);
    }

    public function test_enable_stays_off_when_dovecot_cannot_find_it(): void
    {
        $mailbox = $this->mailbox('security@example.test');
        $this->dovecotMissing = ['security@example.test'];

        $result = $this->health()->enable($mailbox);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['checks'][2]['ok']);
        $this->assertSame('disabled', $mailbox->fresh()->status);
        $this->assertStringContainsString("doesn't exist", (string) $mailbox->fresh()->health_error);
    }

    public function test_enable_skips_dovecot_when_storage_is_missing(): void
    {
        $mailbox = $this->mailbox('a@example.test', 'disabled', ['mail_home' => null]);

        $result = $this->health()->enable($mailbox);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['checks'][0]['ok']);
        $this->assertStringContainsString('mail:migrate-dovecot-sql', $result['checks'][0]['message']);
        $this->assertSame([], $this->statusDuringLookup);
        $this->assertSame('disabled', $mailbox->fresh()->status);
    }

    public function test_enable_fails_on_a_password_hash_that_does_not_match(): void
    {
        $mailbox = $this->mailbox('a@example.test', 'disabled', ['password' => MailPasswordHash::make('other')]);

        $result = $this->health()->enable($mailbox);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['checks'][1]['ok']);
    }

    public function test_enable_stays_off_when_dovecot_cannot_answer(): void
    {
        $mailbox = $this->mailbox('a@example.test');
        $this->dovecotError = 'doveadm user failed (exit 75): auth service down';

        $result = $this->health()->enable($mailbox);

        $this->assertFalse($result['enabled']);
        $this->assertStringContainsString('auth service down', $result['checks'][2]['message']);
        $this->assertSame('disabled', $mailbox->fresh()->status);
    }

    public function test_disable_always_turns_off(): void
    {
        $mailbox = $this->mailbox('a@example.test', 'active');

        $this->health()->disable($mailbox);

        $this->assertSame('disabled', $mailbox->fresh()->status);
    }

    public function test_report_records_problems_without_changing_status(): void
    {
        $ok = $this->mailbox('ok@example.test', 'active');
        $broken = $this->mailbox('security@example.test', 'active');
        $this->dovecotMissing = ['security@example.test'];

        $report = $this->health()->report();

        $this->assertSame(['security@example.test'], $report['problems']);
        $this->assertSame('active', $broken->fresh()->status);
        $this->assertNotNull($broken->fresh()->health_error);
        $this->assertNull($ok->fresh()->health_error);
    }

    public function test_report_with_dovecot_down_records_nothing(): void
    {
        $mailbox = $this->mailbox('a@example.test', 'active');
        $this->dovecotError = 'auth service down';

        $report = $this->health()->report();

        $this->assertFalse($report['ok']);
        $this->assertNull($mailbox->fresh()->health_checked_at);
    }
}
