<?php

namespace Tests\Feature\Mail;

use App\Http\Controllers\EmailController;
use App\Models\Mailbox;
use App\Services\Mail\MailboxImapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MailboxDeliveryModeTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(string $email, array $attributes = []): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate($attributes + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local,
            'email' => $email, 'password' => 'x', 'status' => 'active',
        ]);
    }

    private function setMode(Mailbox $mailbox, string $mode): array
    {
        $request = Request::create('/', 'PATCH', ['delivery_mode' => $mode]);

        return app(EmailController::class)->setDeliveryMode($request, 'token', $mailbox->id)->getData(true);
    }

    public function test_new_mailboxes_are_live(): void
    {
        $mailbox = $this->mailbox('a@example.test')->fresh();

        $this->assertSame(Mailbox::MODE_LIVE, $mailbox->delivery_mode);
        $this->assertFalse($mailbox->isSandbox());
    }

    public function test_mode_switches_between_sandbox_and_live(): void
    {
        $mailbox = $this->mailbox('a@example.test');

        $data = $this->setMode($mailbox, 'sandbox');
        $this->assertTrue($data['ok']);
        $this->assertSame('sandbox', $data['mailbox']['delivery_mode']);
        $this->assertTrue($mailbox->fresh()->isSandbox());

        $this->setMode($mailbox, 'live');
        $this->assertFalse($mailbox->fresh()->isSandbox());
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $mailbox = $this->mailbox('a@example.test');

        $this->expectException(ValidationException::class);
        $this->setMode($mailbox, 'production');
    }

    public function test_only_recipients_on_hosted_domains_count_as_local(): void
    {
        $this->mailbox('a@example.test');
        $this->mailbox('b@Other.test');
        $this->mailbox('c@off.test', ['status' => 'disabled']);

        $local = app(MailboxImapService::class)->hostedRecipients([
            'new@example.test', 'b@other.test', 'x@gmail.com', 'c@off.test',
        ]);

        $this->assertSame(['new@example.test', 'b@other.test'], $local);
    }
}
