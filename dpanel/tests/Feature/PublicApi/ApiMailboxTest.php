<?php

namespace Tests\Feature\PublicApi;

use App\Models\Mailbox;
use App\Services\Mail\Backend\MailBackend;
use App\Support\MailPasswordHash;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/** Backlog U1: mailboxes through /api/v1, only on the owner's domains. */
class ApiMailboxTest extends PublicApiTestCase
{
    private MockInterface $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = $this->mock(MailBackend::class);
    }

    public function test_a_mailbox_is_made_on_the_users_own_domain(): void
    {
        $customer = $this->user();
        // The storage path is worked out from a real system account.
        $this->website($customer, 'shop.test', ['site_owner' => 'root']);
        $this->mail->shouldReceive('ensureMailbox')->once();
        $this->mail->shouldReceive('ensureDomain')->once()->with('shop.test')->andReturn(['ok' => true, 'messages' => []]);

        $this->api($this->token($customer, ['mail:write']), 'POST', '/mailboxes', ['email' => 'Info@Shop.test', 'password' => 'a-long-password', 'quota_mb' => 500])
            ->assertCreated()
            ->assertJsonPath('data.email', 'info@shop.test')
            ->assertJsonPath('data.quota_mb', 500);

        $mailbox = Mailbox::query()->sole();
        $this->assertSame($customer->id, (int) $mailbox->created_by);
        $this->assertTrue(MailPasswordHash::verify('a-long-password', (string) $mailbox->password));
    }

    public function test_a_mailbox_cannot_be_made_on_someone_elses_domain(): void
    {
        $customer = $this->user();
        $this->website($this->user(), 'theirs.test', ['site_owner' => 'root']);
        $this->mail->shouldNotReceive('ensureMailbox');

        $this->api($this->token($customer, ['mail:write']), 'POST', '/mailboxes', ['email' => 'info@theirs.test', 'password' => 'a-long-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, Mailbox::query()->count());
    }

    public function test_the_owner_lists_changes_the_password_of_and_deletes_their_mailbox(): void
    {
        $customer = $this->user();
        $mine = $this->mailbox('info@shop.test', $customer->id);
        $theirs = $this->mailbox('info@theirs.test', $this->user()->id);
        $plain = $this->token($customer, ['mail:write']);

        $this->api($plain, 'GET', '/mailboxes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'info@shop.test');

        $this->api($plain, 'PUT', "/mailboxes/{$theirs->id}/password", ['password' => 'new-password-1'])->assertNotFound();
        $this->api($plain, 'PUT', "/mailboxes/{$mine->id}/password", ['password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->api($plain, 'PUT', "/mailboxes/{$mine->id}/password", ['password' => 'new-password-1'])->assertOk();
        $this->assertTrue(MailPasswordHash::verify('new-password-1', (string) $mine->refresh()->password));
        $this->assertSame('new-password-1', $mine->client_password);

        $this->mail->shouldReceive('removeMailbox')->once();
        $this->api($plain, 'DELETE', "/mailboxes/{$theirs->id}")->assertNotFound();
        $this->api($plain, 'DELETE', "/mailboxes/{$mine->id}")->assertOk();
        $this->assertSame([$theirs->id], Mailbox::query()->pluck('id')->all());
    }

    private function mailbox(string $email, int $owner): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local, 'email' => $email,
            'password' => 'x', 'quota_mb' => 100, 'status' => 'active', 'created_by' => $owner,
        ]);
    }
}
