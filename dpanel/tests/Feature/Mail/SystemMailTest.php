<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SystemMailTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function mailbox(string $email, array $attributes = []): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate($attributes + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local, 'email' => $email,
            'password' => 'x', 'client_password' => 'secret-pass', 'status' => 'active', 'delivery_mode' => 'live',
        ]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_a_fresh_panel_with_the_log_mailer_is_not_ready(): void
    {
        config(['mail.default' => 'log']);
        $status = app(SystemMailer::class)->status();

        $this->assertFalse($status['ready']);
        $this->assertSame('none', $status['source']);
        $this->assertStringContainsString('"log"', $status['problem']);
    }

    public function test_a_real_mailer_in_env_counts_as_ready(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'ops@example.test']);

        $this->assertSame(['ready' => true, 'source' => 'env', 'email' => 'ops@example.test', 'problem' => null], app(SystemMailer::class)->status());
    }

    public function test_panel_mail_is_sent_through_the_system_mailbox(): void
    {
        $mailbox = $this->mailbox('noreply@panel.test');
        app(MailSettings::class)->write(['system_mailbox_id' => $mailbox->id]);
        app()->forgetInstance('mail.manager');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');

        app('mail.manager');

        $this->assertSame('system', config('mail.default'));
        $this->assertSame('noreply@panel.test', config('mail.mailers.system.username'));
        $this->assertSame('secret-pass', config('mail.mailers.system.password'));
        $this->assertSame(587, config('mail.mailers.system.port'));
        $this->assertSame('noreply@panel.test', config('mail.from.address'));
        $this->assertTrue(app(SystemMailer::class)->status()['ready']);
    }

    public function test_unusable_mailboxes_are_reported_and_not_applied(): void
    {
        $mailer = app(SystemMailer::class);

        $this->assertStringContainsString('sandbox', $mailer->problemWith($this->mailbox('a@x.test', ['delivery_mode' => 'sandbox'])));
        $this->assertStringContainsString('turned off', $mailer->problemWith($this->mailbox('b@x.test', ['status' => 'disabled'])));
        $this->assertStringContainsString('password', $mailer->problemWith($this->mailbox('c@x.test', ['client_password' => null])));

        $sandbox = Mailbox::query()->where('email', 'a@x.test')->first();
        app(MailSettings::class)->write(['system_mailbox_id' => $sandbox->id]);
        config(['mail.default' => 'log']);
        $mailer->apply();
        $this->assertSame('log', config('mail.default'));
        $status = $mailer->status();
        $this->assertFalse($status['ready']);
        $this->assertSame('system', $status['source']);
    }

    public function test_admin_can_choose_a_mailbox_and_send_a_test(): void
    {
        Mail::fake();
        $admin = $this->user('admin');
        $sandbox = $this->mailbox('test@panel.test', ['delivery_mode' => 'sandbox']);
        $good = $this->mailbox('noreply@panel.test');

        $this->as($admin)->put("/cpsess{$this->token}/emails/system-mail", ['mailbox_id' => $sandbox->id])->assertSessionHas('error');
        $this->assertNull(app(MailSettings::class)->read()['system_mailbox_id']);

        $this->as($admin)->put("/cpsess{$this->token}/emails/system-mail", ['mailbox_id' => $good->id])->assertSessionHas('success');
        $this->assertSame($good->id, app(MailSettings::class)->read()['system_mailbox_id']);

        $this->as($admin)->postJson("/cpsess{$this->token}/emails/system-mail/test", ['to' => 'me@example.test'])->assertOk()->assertJson(['ok' => true]);

        $this->as($admin)->delete("/cpsess{$this->token}/emails/system-mail")->assertSessionHas('success');
        $this->assertNull(app(MailSettings::class)->read()['system_mailbox_id']);
    }

    public function test_only_admins_see_the_dashboard_warning(): void
    {
        config(['mail.default' => 'log']);

        $this->as($this->user('admin'))->get("/cpsess{$this->token}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('systemMailWarning.source', 'none'));
        $this->as($this->user('reseller'))->get("/cpsess{$this->token}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('systemMailWarning', null));

        $mailbox = $this->mailbox('noreply@panel.test');
        app(MailSettings::class)->write(['system_mailbox_id' => $mailbox->id]);
        $this->as($this->user('admin'))->get("/cpsess{$this->token}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('systemMailWarning', null));
    }
}
